<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Support\StatusConstraintDatabase;

return new class extends Migration
{
    private const string MIGRATION_NAME =
        '2026_07_29_000000_create_mail_notification_tables';

    private const string NOTIFICATION_STATUS_CONSTRAINT =
        'mail_notifications_status_check';

    private const string EVENT_STATUS_CONSTRAINT =
        'mail_notification_events_normalized_type_check';

    /**
     * Create provider-neutral mail tracking tables.
     */
    public function up(): void
    {
        $configuredConnection = config('mail-notifications.storage.connection');
        $connection = is_string($configuredConnection) && $configuredConnection !== ''
            ? $configuredConnection
            : null;
        $configuredNotificationTable = config(
            'mail-notifications.storage.tables.notifications',
            'mail_notifications',
        );
        $notificationTable = is_string($configuredNotificationTable)
            && $configuredNotificationTable !== ''
                ? $configuredNotificationTable
                : 'mail_notifications';
        $configuredEventTable = config(
            'mail-notifications.storage.tables.events',
            'mail_notification_events',
        );
        $eventTable = is_string($configuredEventTable) && $configuredEventTable !== ''
            ? $configuredEventTable
            : 'mail_notification_events';
        $schema = Schema::connection($connection);
        $this->assertCreatorMayProceed(
            schema: $schema,
            notificationTable: $notificationTable,
            eventTable: $eventTable,
        );
        $identityCollation = in_array(
            $schema->getConnection()->getDriverName(),
            ['mysql', 'mariadb'],
            true,
        ) ? 'utf8mb4_bin' : null;
        $database = $schema->getConnection();
        $this->assertSupportedStatusDriver($database);
        $notificationCreated = false;
        $eventCreated = false;

        if (! $schema->hasTable($notificationTable)) {
            $schema->create($notificationTable, function (Blueprint $table) use ($identityCollation): void {
                $table->uuid('id')->primary();
                $table->uuid('correlation_id')->unique();
                $table->uuid('queue_reference')->nullable();
                $table->string('mailer', 128);
                $provider = $table->string('provider', 128)->nullable();
                $providerMessageId = $table->string('provider_message_id', 255)
                    ->nullable();

                if ($identityCollation !== null) {
                    $provider->collation($identityCollation);
                    $providerMessageId->collation($identityCollation);
                }

                $table->string('status', 32)->default('pending');
                $table->string('message_category', 128);
                $table->text('subject')->nullable();
                $table->string('from_email', 254)->nullable();
                $table->text('from_name')->nullable();
                $table->json('to_recipients');
                $table->json('cc_recipients')->nullable();
                $table->json('bcc_recipients')->nullable();
                $table->string('primary_recipient_email', 254)->nullable();
                $table->string('notifiable_type', 128)->nullable();
                $table->string('notifiable_id', 128)->nullable();
                $table->json('metadata')->nullable();
                $table->timestampTz('accepted_at', 6)->nullable();
                $table->timestampTz('delivered_at', 6)->nullable();
                $table->timestampTz('failed_at', 6)->nullable();
                $table->timestampTz('status_changed_at', 6)->nullable();
                $table->timestampTz('provider_occurred_at', 6)->nullable();
                $table->timestampTz('redacted_at', 6)->nullable();
                $table->timestampsTz(6);

                $table->unique(
                    ['provider', 'provider_message_id'],
                    'mail_notifications_provider_message_unique',
                );
                $table->index(
                    ['notifiable_type', 'notifiable_id', 'created_at'],
                    'mail_notifications_notifiable_created_index',
                );
                $table->index(
                    ['status', 'created_at'],
                    'mail_notifications_status_created_index',
                );
                $table->index(
                    ['status', 'status_changed_at'],
                    'mail_notifications_status_changed_index',
                );
                $table->index(
                    ['primary_recipient_email', 'created_at'],
                    'mail_notifications_recipient_created_index',
                );
                $table->index(
                    ['queue_reference', 'created_at'],
                    'mail_notifications_queue_created_index',
                );
                $table->index(
                    ['redacted_at', 'status', 'status_changed_at', 'id'],
                    'mail_notifications_redacted_time_index',
                );
            });
            $notificationCreated = true;
        }

        if (! $schema->hasTable($eventTable)) {
            $schema->create($eventTable, function (Blueprint $table) use (
                $identityCollation,
                $notificationTable,
            ): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('mail_notification_id')
                    ->constrained($notificationTable)
                    ->cascadeOnDelete();
                $provider = $table->string('provider', 128);
                $providerEventId = $table->string('provider_event_id', 255);
                $providerMessageId = $table->string('provider_message_id', 255)
                    ->nullable();

                if ($identityCollation !== null) {
                    $provider->collation($identityCollation);
                    $providerEventId->collation($identityCollation);
                    $providerMessageId->collation($identityCollation);
                }

                $table->string('normalized_type', 32);
                $table->timestampTz('occurred_at', 6);
                $table->json('metadata')->nullable();
                $table->timestampTz('processed_at', 6);
                $table->timestampTz('redacted_at', 6)->nullable();
                $table->timestampsTz(6);

                $table->unique(
                    ['provider', 'provider_event_id'],
                    'mail_notification_events_provider_event_unique',
                );
                $table->index(
                    ['mail_notification_id', 'occurred_at'],
                    'mail_notification_events_notification_time_index',
                );
                $table->index(
                    ['redacted_at', 'occurred_at', 'id'],
                    'mail_notification_events_redacted_time_index',
                );
            });
            $eventCreated = true;
        }

        $allowedStatuses = array_map(
            static fn (MailDeliveryStatus $status): string => $status->value,
            MailDeliveryStatus::cases(),
        );

        if ($notificationCreated) {
            $this->installStatusInvariant(
                connection: $database,
                table: $notificationTable,
                column: 'status',
                constraint: self::NOTIFICATION_STATUS_CONSTRAINT,
                allowedValues: $allowedStatuses,
            );
        }

        if ($eventCreated) {
            $this->installStatusInvariant(
                connection: $database,
                table: $eventTable,
                column: 'normalized_type',
                constraint: self::EVENT_STATUS_CONSTRAINT,
                allowedValues: $allowedStatuses,
            );
        }
    }

    /**
     * Retain mail lifecycle history during framework migration rollback.
     */
    public function down(): void {}

    /**
     * Refuse to adopt retained or partially created tracking tables.
     */
    private function assertCreatorMayProceed(
        Builder $schema,
        string $notificationTable,
        string $eventTable,
    ): void {
        $notificationExists = $schema->hasTable($notificationTable);
        $eventExists = $schema->hasTable($eventTable);

        if ($this->migrationWasRecorded()
            || (! $notificationExists && ! $eventExists)) {
            return;
        }

        $existingTables = [];

        if ($notificationExists) {
            $existingTables[] = $notificationTable;
        }

        if ($eventExists) {
            $existingTables[] = $eventTable;
        }

        throw new LogicException(sprintf(
            'Package tracking schema creator [%s] is pending, but configured tracking table(s) already exist: %s. This indicates retained or partially created schema after an interrupted migration. Restore the complete owned schema and migration history, migrate the retained schema intentionally, or configure fresh package-owned table names before retrying.',
            self::MIGRATION_NAME,
            implode(', ', $existingTables),
        ));
    }

    /**
     * Fail before creating partial schema on a driver without exact invariants.
     */
    private function assertSupportedStatusDriver(Connection $connection): void
    {
        StatusConstraintDatabase::assertSupported($connection);
    }

    /**
     * Install one driver-native status invariant.
     *
     * @param  list<string>  $allowedValues
     */
    private function installStatusInvariant(
        Connection $connection,
        string $table,
        string $column,
        string $constraint,
        array $allowedValues,
    ): void {
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $this->installSqliteStatusInvariant(
                $connection,
                $table,
                $column,
                $constraint,
                $allowedValues,
            );

            return;
        }

        $grammar = $connection->getQueryGrammar();
        $wrappedColumn = $grammar->wrap($column);
        $checkedColumn = in_array($driver, ['mysql', 'mariadb'], true)
            ? 'binary '.$wrappedColumn
            : $wrappedColumn;
        $connection->statement(sprintf(
            'alter table %s add constraint %s check (%s in (%s))',
            $grammar->wrapTable($table),
            $grammar->wrap($constraint),
            $checkedColumn,
            $this->quotedValues($allowedValues),
        ));
    }

    /**
     * Install paired SQLite triggers without rebuilding a host-extended table.
     *
     * @param  list<string>  $allowedValues
     */
    private function installSqliteStatusInvariant(
        Connection $connection,
        string $table,
        string $column,
        string $constraint,
        array $allowedValues,
    ): void {
        $grammar = $connection->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumn = $grammar->wrap($column);
        $quotedValues = $this->quotedValues($allowedValues);
        $error = $this->quoteValue(
            "Status invariant [{$constraint}] rejected an invalid value.",
        );
        $connection->statement(sprintf(
            'create trigger %s before insert on %s for each row when new.%s not in (%s) begin select raise(abort, %s); end',
            $grammar->wrap($constraint.'_insert'),
            $wrappedTable,
            $wrappedColumn,
            $quotedValues,
            $error,
        ));
        $connection->statement(sprintf(
            'create trigger %s before update of %s on %s for each row when new.%s not in (%s) begin select raise(abort, %s); end',
            $grammar->wrap($constraint.'_update'),
            $wrappedColumn,
            $wrappedTable,
            $wrappedColumn,
            $quotedValues,
            $error,
        ));
    }

    /**
     * Render an SQL string-list literal from trusted enum values.
     *
     * @param  list<string>  $values
     */
    private function quotedValues(array $values): string
    {
        return implode(', ', array_map(
            $this->quoteValue(...),
            $values,
        ));
    }

    /**
     * Quote one trusted enum or error-message literal.
     */
    private function quoteValue(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Determine whether Laravel has recorded this exact package migration.
     */
    private function migrationWasRecorded(): bool
    {
        $migrator = app(Migrator::class);

        return $migrator->repositoryExists()
            && in_array(
                self::MIGRATION_NAME,
                $migrator->getRepository()->getRan(),
                true,
            );
    }
};
