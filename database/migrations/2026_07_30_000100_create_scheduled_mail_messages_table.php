<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Support\StatusConstraintDatabase;
use Nvl\MailNotifications\Support\StatusConstraintInspector;

return new class extends Migration
{
    private const string MIGRATION_NAME = '2026_07_30_000100_create_scheduled_mail_messages_table';

    private const string STATUS_CONSTRAINT =
        'scheduled_mail_messages_status_check';

    /**
     * Create durable provider-neutral scheduled-mail storage.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connectionName());
        $tableName = $this->tableName();
        $connection = $schema->getConnection();
        $this->assertSupportedStatusDriver($connection);

        if ($schema->hasTable($tableName)) {
            if (! $this->migrationWasRecorded()) {
                throw new LogicException(sprintf(
                    'Existing scheduled mail table [%s] cannot be adopted because the package migration [%s] is not recorded. Set mail-notifications.migrations.enabled=false and manage this table with a host migration, or configure a fresh package table.',
                    $tableName,
                    self::MIGRATION_NAME,
                ));
            }

            $this->assertCompatible($schema, $tableName);

            return;
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('factory_alias', 128);
            $table->unsignedSmallInteger('payload_version');
            $table->json('payload');
            $table->json('to_recipients');
            $table->json('cc_recipients')->nullable();
            $table->json('bcc_recipients')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestampTz('scheduled_for', 6);
            $table->timestampTz('available_at', 6);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestampTz('last_attempt_at', 6)->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('locked_until', 6)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->string('notifiable_type', 128)->nullable();
            $table->string('notifiable_id', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('sent_at', 6)->nullable();
            $table->timestampTz('failed_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $table->timestampTz('redacted_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index(
                ['status', 'available_at', 'id'],
                'scheduled_mail_due_index',
            );
            $table->index(
                ['status', 'locked_until'],
                'scheduled_mail_recovery_index',
            );
            $table->index(
                ['notifiable_type', 'notifiable_id', 'created_at'],
                'scheduled_mail_notifiable_index',
            );
            $table->index('claim_token', 'scheduled_mail_claim_index');
            $table->index(
                ['status', 'sent_at'],
                'scheduled_mail_retention_sent_index',
            );
            $table->index(
                ['status', 'failed_at'],
                'scheduled_mail_retention_failed_index',
            );
            $table->index(
                ['status', 'cancelled_at'],
                'scheduled_mail_retention_cancelled_index',
            );
            $table->index(
                ['status', 'updated_at'],
                'scheduled_mail_retention_fallback_index',
            );
            $table->index(
                ['redacted_at', 'updated_at', 'id'],
                'scheduled_mail_messages_redacted_time_index',
            );
        });

        $this->installStatusInvariant(
            connection: $connection,
            table: $tableName,
            column: 'status',
            constraint: self::STATUS_CONSTRAINT,
            allowedValues: $this->allowedStatuses(),
        );
    }

    /**
     * Retain scheduled-mail history during framework migration rollback.
     */
    public function down(): void {}

    /**
     * Resolve the configured storage connection.
     */
    private function connectionName(): ?string
    {
        $configured = config('mail-notifications.storage.connection');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : null;
    }

    /**
     * Resolve the configured scheduled-message table.
     */
    private function tableName(): string
    {
        $configured = config(
            'mail-notifications.storage.tables.scheduled_messages',
            'scheduled_mail_messages',
        );

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : 'scheduled_mail_messages';
    }

    /**
     * Refuse to silently adopt an incompatible pre-existing table.
     */
    private function assertCompatible(Builder $schema, string $table): void
    {
        $required = [
            'id',
            'factory_alias',
            'payload_version',
            'payload',
            'to_recipients',
            'cc_recipients',
            'bcc_recipients',
            'status',
            'scheduled_for',
            'available_at',
            'attempts',
            'max_attempts',
            'last_attempt_at',
            'claim_token',
            'locked_until',
            'last_error',
            'notifiable_type',
            'notifiable_id',
            'metadata',
            'sent_at',
            'failed_at',
            'cancelled_at',
            'redacted_at',
            'created_at',
            'updated_at',
        ];
        $missing = array_values(array_filter(
            $required,
            static fn (string $column): bool => ! $schema->hasColumn(
                $table,
                $column,
            ),
        ));

        if ($missing !== []) {
            throw new LogicException(sprintf(
                'Existing scheduled mail table [%s] is incompatible with nvl/mail-notifications; missing columns: %s.',
                $table,
                implode(', ', $missing),
            ));
        }

        $this->assertPrivacyMarkerDefinition($schema, $table);

        $missingIndexes = array_keys(array_filter([
            'due-message claim lookup' => ! $this->hasNamedIndex(
                $schema,
                $table,
                'scheduled_mail_due_index',
                ['status', 'available_at', 'id'],
            ),
            'expired-claim recovery lookup' => ! $this->hasNamedIndex(
                $schema,
                $table,
                'scheduled_mail_recovery_index',
                ['status', 'locked_until'],
            ),
            'claim-token fencing lookup' => ! $this->hasNamedIndex(
                $schema,
                $table,
                'scheduled_mail_claim_index',
                ['claim_token'],
            ),
            'notifiable timeline lookup' => ! $this->hasNamedIndex(
                $schema,
                $table,
                'scheduled_mail_notifiable_index',
                ['notifiable_type', 'notifiable_id', 'created_at'],
            ),
            'sent retention lookup' => ! $this->hasNamedIndex(
                $schema,
                $table,
                'scheduled_mail_retention_sent_index',
                ['status', 'sent_at'],
            ),
            'failed retention lookup' => ! $this->hasNamedIndex(
                $schema,
                $table,
                'scheduled_mail_retention_failed_index',
                ['status', 'failed_at'],
            ),
            'cancelled retention lookup' => ! $this->hasNamedIndex(
                $schema,
                $table,
                'scheduled_mail_retention_cancelled_index',
                ['status', 'cancelled_at'],
            ),
            'legacy retention fallback lookup' => ! $this->hasNamedIndex(
                $schema,
                $table,
                'scheduled_mail_retention_fallback_index',
                ['status', 'updated_at'],
            ),
            'privacy retention lookup' => ! $this->hasNamedIndex(
                $schema,
                $table,
                'scheduled_mail_messages_redacted_time_index',
                ['redacted_at', 'updated_at', 'id'],
            ),
        ]));

        if ($missingIndexes !== []) {
            throw new LogicException(sprintf(
                'Existing scheduled mail table [%s] is incompatible with nvl/mail-notifications; missing indexes: %s.',
                $table,
                implode(', ', $missingIndexes),
            ));
        }

        if (! StatusConstraintInspector::matches(
            connection: $schema->getConnection(),
            table: $table,
            column: 'status',
            constraint: self::STATUS_CONSTRAINT,
            allowedValues: $this->allowedStatuses(),
        )) {
            throw new LogicException(sprintf(
                'Existing scheduled mail table [%s] is incompatible with nvl/mail-notifications; missing exact status invariant [%s].',
                $table,
                self::STATUS_CONSTRAINT,
            ));
        }
    }

    /**
     * Require one exact named index and ordered column list.
     *
     * @param  list<string>  $columns
     */
    private function hasNamedIndex(
        Builder $schema,
        string $table,
        string $name,
        array $columns,
    ): bool {
        $index = collect($schema->getIndexes($table))->first(
            static fn (array $candidate): bool => strtolower(
                (string) ($candidate['name'] ?? ''),
            ) === strtolower($name),
        );

        if (! is_array($index)) {
            return false;
        }

        $existingColumns = $index['columns'] ?? null;

        return is_array($existingColumns)
            && count($existingColumns) === count($columns)
            && array_values(array_filter(
                $existingColumns,
                'is_string',
            )) === $columns;
    }

    /**
     * Require an exact nullable timestamp privacy marker definition.
     */
    private function assertPrivacyMarkerDefinition(
        Builder $schema,
        string $table,
    ): void {
        $column = collect($schema->getColumns($table))->first(
            static fn (array $candidate): bool => strtolower(
                (string) ($candidate['name'] ?? ''),
            ) === 'redacted_at',
        );

        if (! is_array($column)) {
            throw new LogicException(sprintf(
                'Privacy marker column [%s.redacted_at] could not be inspected.',
                $table,
            ));
        }

        $typeName = strtolower((string) ($column['type_name'] ?? ''));
        $type = strtolower((string) ($column['type'] ?? ''));
        $driver = $schema->getConnection()->getDriverName();
        $expectedType = match ($driver) {
            'sqlite' => $typeName === 'datetime'
                && $type === 'datetime',
            'mysql', 'mariadb' => $typeName === 'timestamp'
                && $type === 'timestamp(6)',
            'pgsql' => $typeName === 'timestamptz'
                && $type === 'timestamp(6) with time zone',
            default => false,
        };
        $expectedShape = $expectedType
            && ($column['nullable'] ?? null) === true
            && ($column['default'] ?? null) === null
            && ($column['auto_increment'] ?? null) === false
            && ($column['generation'] ?? null) === null;

        if (! $expectedShape) {
            throw new LogicException(sprintf(
                'Existing privacy marker [%s.redacted_at] must be an exact nullable microsecond timestamp without a default.',
                $table,
            ));
        }
    }

    /**
     * Fail before creating partial schema on a driver without exact invariants.
     */
    private function assertSupportedStatusDriver(Connection $connection): void
    {
        StatusConstraintDatabase::assertSupported($connection);
    }

    /**
     * Return the exact scheduled-status allowlist.
     *
     * @return list<string>
     */
    private function allowedStatuses(): array
    {
        return array_map(
            static fn (ScheduledMailStatus $status): string => $status->value,
            ScheduledMailStatus::cases(),
        );
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
        $configured = config('database.migrations.table', 'migrations');
        $table = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : 'migrations';
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();

        return $schema->hasTable($table)
            && $connection->table($table)
                ->where('migration', self::MIGRATION_NAME)
                ->exists();
    }
};
