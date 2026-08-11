<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Support\ForeignKeyInspector;
use Nvl\MailNotifications\Support\StatusConstraintDatabase;
use Nvl\MailNotifications\Support\StatusConstraintInspector;

return new class extends Migration
{
    private const string CREATOR_MIGRATION =
        '2026_07_29_000000_create_mail_notification_tables';

    /**
     * Columns required by the notification lifecycle.
     *
     * @var list<string>
     */
    private const array NOTIFICATION_COLUMNS = [
        'id',
        'correlation_id',
        'queue_reference',
        'mailer',
        'provider',
        'provider_message_id',
        'status',
        'message_category',
        'subject',
        'from_email',
        'from_name',
        'to_recipients',
        'cc_recipients',
        'bcc_recipients',
        'primary_recipient_email',
        'notifiable_type',
        'notifiable_id',
        'metadata',
        'accepted_at',
        'delivered_at',
        'failed_at',
        'status_changed_at',
        'provider_occurred_at',
        'redacted_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Columns required by durable provider-event idempotency.
     *
     * @var list<string>
     */
    private const array EVENT_COLUMNS = [
        'id',
        'mail_notification_id',
        'provider',
        'provider_event_id',
        'provider_message_id',
        'normalized_type',
        'occurred_at',
        'metadata',
        'processed_at',
        'redacted_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Expected coarse storage type for every notification column.
     *
     * @var array<string, string>
     */
    private const array NOTIFICATION_COLUMN_TYPES = [
        'id' => 'identity',
        'correlation_id' => 'identity',
        'queue_reference' => 'identity',
        'mailer' => 'string',
        'provider' => 'string',
        'provider_message_id' => 'string',
        'status' => 'string',
        'message_category' => 'string',
        'subject' => 'text',
        'from_email' => 'string',
        'from_name' => 'text',
        'to_recipients' => 'json',
        'cc_recipients' => 'json',
        'bcc_recipients' => 'json',
        'primary_recipient_email' => 'string',
        'notifiable_type' => 'string',
        'notifiable_id' => 'string',
        'metadata' => 'json',
        'accepted_at' => 'timestamp',
        'delivered_at' => 'timestamp',
        'failed_at' => 'timestamp',
        'status_changed_at' => 'timestamp',
        'provider_occurred_at' => 'timestamp',
        'redacted_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * Expected coarse storage type for every provider-event column.
     *
     * @var array<string, string>
     */
    private const array EVENT_COLUMN_TYPES = [
        'id' => 'identity',
        'mail_notification_id' => 'identity',
        'provider' => 'string',
        'provider_event_id' => 'string',
        'provider_message_id' => 'string',
        'normalized_type' => 'string',
        'occurred_at' => 'timestamp',
        'metadata' => 'json',
        'processed_at' => 'timestamp',
        'redacted_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * Notification columns that must accept null.
     *
     * @var list<string>
     */
    private const array NULLABLE_NOTIFICATION_COLUMNS = [
        'queue_reference',
        'provider',
        'provider_message_id',
        'subject',
        'from_email',
        'from_name',
        'cc_recipients',
        'bcc_recipients',
        'primary_recipient_email',
        'notifiable_type',
        'notifiable_id',
        'metadata',
        'accepted_at',
        'delivered_at',
        'failed_at',
        'status_changed_at',
        'provider_occurred_at',
        'redacted_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Provider-event columns that must accept null.
     *
     * @var list<string>
     */
    private const array NULLABLE_EVENT_COLUMNS = [
        'provider_message_id',
        'metadata',
        'redacted_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Declared string lengths where the database exposes length metadata.
     *
     * @var array<string, int>
     */
    private const array NOTIFICATION_COLUMN_LENGTHS = [
        'id' => 36,
        'correlation_id' => 36,
        'queue_reference' => 36,
        'mailer' => 128,
        'provider' => 128,
        'provider_message_id' => 255,
        'status' => 32,
        'message_category' => 128,
        'from_email' => 254,
        'primary_recipient_email' => 254,
        'notifiable_type' => 128,
        'notifiable_id' => 128,
    ];

    /**
     * Declared event string lengths where the database exposes length metadata.
     *
     * @var array<string, int>
     */
    private const array EVENT_COLUMN_LENGTHS = [
        'id' => 36,
        'mail_notification_id' => 36,
        'provider' => 128,
        'provider_event_id' => 255,
        'provider_message_id' => 255,
        'normalized_type' => 32,
    ];

    /**
     * Refuse to silently adopt tables owned by an incompatible legacy module.
     */
    public function up(): void
    {
        $configuredConnection = config('mail-notifications.storage.connection');
        $connection = is_string($configuredConnection)
            && $configuredConnection !== ''
                ? $configuredConnection
                : null;
        $schema = Schema::connection($connection);
        StatusConstraintDatabase::assertSupported(
            $schema->getConnection(),
        );

        $notificationTable = $this->configuredTable(
            'mail-notifications.storage.tables.notifications',
            MailNotificationsTables::Notifications,
        );
        $eventTable = $this->configuredTable(
            'mail-notifications.storage.tables.events',
            MailNotificationsTables::Events,
        );

        $notificationExists = $schema->hasTable($notificationTable);
        $eventExists = $schema->hasTable($eventTable);
        $creatorRecorded = $this->creatorMigrationRecorded();

        if (! $notificationExists && ! $eventExists) {
            if ($creatorRecorded) {
                throw new LogicException(sprintf(
                    'The package schema creator [%s] is recorded, but its configured mail notification tables [%s] and [%s] are missing. Restore the owned schema or move to fresh table names with an intentional migration baseline.',
                    self::CREATOR_MIGRATION,
                    $notificationTable,
                    $eventTable,
                ));
            }

            return;
        }

        if (! $notificationExists) {
            throw new LogicException(sprintf(
                'Existing mail notification event table [%s] cannot be adopted without its configured notification table [%s]. Configure fresh package tables or migrate the partial legacy schema before continuing.',
                $eventTable,
                $notificationTable,
            ));
        }

        $this->assertNotificationStructure(
            schema: $schema,
            table: $notificationTable,
        );

        if (! $eventExists) {
            if ($creatorRecorded) {
                throw new LogicException(sprintf(
                    'The package schema creator [%s] is recorded, but its configured event table [%s] is missing. Restore the owned table before continuing; the recorded creator will not run again.',
                    self::CREATOR_MIGRATION,
                    $eventTable,
                ));
            }

            throw new LogicException(sprintf(
                'Existing compatible mail notification table [%s] has ambiguous ownership because package schema creator [%s] is not recorded and its configured event table [%s] is missing. Disable package migrations and baseline host ownership intentionally, or configure fresh package-owned table names; the package refuses to evolve a partial schema without one recorded owner.',
                $notificationTable,
                self::CREATOR_MIGRATION,
                $eventTable,
            ));
        }

        $this->assertEventStructure(
            schema: $schema,
            notificationTable: $notificationTable,
            eventTable: $eventTable,
        );

        if (! $creatorRecorded) {
            throw new LogicException(sprintf(
                'Existing compatible mail notification tables [%s] and [%s] have ambiguous ownership because package schema creator [%s] is not recorded. Disable package migrations and baseline host ownership intentionally, or configure fresh package-owned table names; the package refuses to evolve tables without one recorded schema owner.',
                $notificationTable,
                $eventTable,
                self::CREATOR_MIGRATION,
            ));
        }
    }

    /**
     * This guard migration does not own schema that can be rolled back.
     */
    public function down(): void {}

    /**
     * Resolve a non-empty configured table name.
     */
    private function configuredTable(string $key, string $fallback): string
    {
        $configured = config($key, $fallback);

        return is_string($configured) && $configured !== ''
            ? $configured
            : $fallback;
    }

    /**
     * Ensure an existing package table exposes every runtime column.
     *
     * @param  list<string>  $requiredColumns
     */
    private function assertRequiredColumns(
        Builder $schema,
        string $table,
        string $label,
        array $requiredColumns,
    ): void {
        $missingColumns = array_values(array_filter(
            $requiredColumns,
            static fn (string $column): bool => ! $schema->hasColumn(
                $table,
                $column,
            ),
        ));

        if ($missingColumns === []) {
            return;
        }

        throw new LogicException(sprintf(
            'Existing %s table [%s] is incompatible with nvl/mail-notifications; missing columns: %s. Configure fresh package tables or migrate the legacy schema before continuing.',
            $label,
            $table,
            implode(', ', $missingColumns),
        ));
    }

    /**
     * Verify the package creator is already owned by Laravel migration history.
     */
    private function creatorMigrationRecorded(): bool
    {
        $migrator = app(Migrator::class);

        return $migrator->repositoryExists()
            && in_array(
                self::CREATOR_MIGRATION,
                $migrator->getRepository()->getRan(),
                true,
            );
    }

    /**
     * Assert the complete notification-table runtime contract.
     */
    private function assertNotificationStructure(
        Builder $schema,
        string $table,
    ): void {
        $this->assertRequiredColumns(
            schema: $schema,
            table: $table,
            label: 'mail notification',
            requiredColumns: self::NOTIFICATION_COLUMNS,
        );
        $this->assertColumnDefinitions(
            schema: $schema,
            table: $table,
            label: 'mail notification',
            typeFamilies: self::NOTIFICATION_COLUMN_TYPES,
            nullableColumns: self::NULLABLE_NOTIFICATION_COLUMNS,
            lengths: self::NOTIFICATION_COLUMN_LENGTHS,
            statusDefault: true,
        );
        $this->assertPrivacyMarkerDefinition($schema, $table);
        $this->assertChecks(
            table: $table,
            label: 'mail notification constraints',
            checks: [
                'primary key' => $schema->hasIndex(
                    $table,
                    ['id'],
                    'primary',
                ),
                'correlation identity' => $schema->hasIndex(
                    $table,
                    ['correlation_id'],
                    'unique',
                ),
                'provider message identity' => $this->hasNamedIndex(
                    $schema,
                    $table,
                    'mail_notifications_provider_message_unique',
                    ['provider', 'provider_message_id'],
                    unique: true,
                ),
                'status allowlist' => StatusConstraintInspector::matches(
                    connection: $schema->getConnection(),
                    table: $table,
                    column: 'status',
                    constraint: 'mail_notifications_status_check',
                    allowedValues: $this->mailDeliveryStatuses(),
                ),
            ],
        );
        $this->assertChecks(
            table: $table,
            label: 'mail notification indexes',
            checks: [
                'notifiable timeline lookup' => $this->hasNamedIndex(
                    $schema,
                    $table,
                    'mail_notifications_notifiable_created_index',
                    ['notifiable_type', 'notifiable_id', 'created_at'],
                ),
                'status timeline lookup' => $this->hasNamedIndex(
                    $schema,
                    $table,
                    'mail_notifications_status_created_index',
                    ['status', 'created_at'],
                ),
                'status-change retention lookup' => $this->hasNamedIndex(
                    $schema,
                    $table,
                    'mail_notifications_status_changed_index',
                    ['status', 'status_changed_at'],
                ),
                'recipient timeline lookup' => $this->hasNamedIndex(
                    $schema,
                    $table,
                    'mail_notifications_recipient_created_index',
                    ['primary_recipient_email', 'created_at'],
                ),
                'queued-failure identity lookup' => $this->hasNamedIndex(
                    $schema,
                    $table,
                    'mail_notifications_queue_created_index',
                    ['queue_reference', 'created_at'],
                ),
                'privacy retention lookup' => $this->hasNamedIndex(
                    $schema,
                    $table,
                    'mail_notifications_redacted_time_index',
                    ['redacted_at', 'status', 'status_changed_at', 'id'],
                ),
            ],
        );
    }

    /**
     * Assert the complete provider-event-table runtime contract.
     */
    private function assertEventStructure(
        Builder $schema,
        string $notificationTable,
        string $eventTable,
    ): void {
        $this->assertRequiredColumns(
            schema: $schema,
            table: $eventTable,
            label: 'mail notification event',
            requiredColumns: self::EVENT_COLUMNS,
        );
        $this->assertColumnDefinitions(
            schema: $schema,
            table: $eventTable,
            label: 'mail notification event',
            typeFamilies: self::EVENT_COLUMN_TYPES,
            nullableColumns: self::NULLABLE_EVENT_COLUMNS,
            lengths: self::EVENT_COLUMN_LENGTHS,
        );
        $this->assertPrivacyMarkerDefinition($schema, $eventTable);
        $this->assertChecks(
            table: $eventTable,
            label: 'mail notification event constraints',
            checks: [
                'primary key' => $schema->hasIndex(
                    $eventTable,
                    ['id'],
                    'primary',
                ),
                'provider event identity' => $this->hasNamedIndex(
                    $schema,
                    $eventTable,
                    'mail_notification_events_provider_event_unique',
                    ['provider', 'provider_event_id'],
                    unique: true,
                ),
                'provider event ownership cascade' => $this
                    ->hasOwnershipCascade(
                        schema: $schema,
                        notificationTable: $notificationTable,
                        eventTable: $eventTable,
                    ),
                'normalized-type allowlist' => StatusConstraintInspector::matches(
                    connection: $schema->getConnection(),
                    table: $eventTable,
                    column: 'normalized_type',
                    constraint: 'mail_notification_events_normalized_type_check',
                    allowedValues: $this->mailDeliveryStatuses(),
                ),
            ],
        );
        $this->assertChecks(
            table: $eventTable,
            label: 'mail notification event indexes',
            checks: [
                'provider event timeline lookup' => $this->hasNamedIndex(
                    $schema,
                    $eventTable,
                    'mail_notification_events_notification_time_index',
                    ['mail_notification_id', 'occurred_at'],
                ),
                'privacy retention lookup' => $this->hasNamedIndex(
                    $schema,
                    $eventTable,
                    'mail_notification_events_redacted_time_index',
                    ['redacted_at', 'occurred_at', 'id'],
                ),
            ],
        );
    }

    /**
     * Assert portable types, nullability, lengths, defaults, and collations.
     *
     * @param  array<string, string>  $typeFamilies
     * @param  list<string>  $nullableColumns
     * @param  array<string, int>  $lengths
     */
    private function assertColumnDefinitions(
        Builder $schema,
        string $table,
        string $label,
        array $typeFamilies,
        array $nullableColumns,
        array $lengths,
        bool $statusDefault = false,
    ): void {
        $columns = collect($schema->getColumns($table))->keyBy(
            static fn (array $column): string => strtolower(
                (string) $column['name'],
            ),
        );
        $failures = [];

        foreach ($typeFamilies as $columnName => $typeFamily) {
            $column = $columns->get($columnName);

            if (! is_array($column)) {
                continue;
            }

            $columnLabel = "{$table}.{$columnName}";
            $typeName = strtolower((string) $column['type_name']);

            if (! $this->columnTypeMatches($typeName, $typeFamily)) {
                $failures[] = "{$columnLabel} type";
            }

            $expectedNullable = in_array(
                $columnName,
                $nullableColumns,
                true,
            );

            if ($column['nullable'] !== $expectedNullable) {
                $failures[] = "{$columnLabel} nullability";
            }

            $expectedLength = $lengths[$columnName] ?? null;
            $declaredType = strtolower((string) $column['type']);

            if ($expectedLength !== null
                && preg_match('/\((\d+)\)/', $declaredType, $matches) === 1
                && (int) $matches[1] !== $expectedLength) {
                $failures[] = "{$columnLabel} length";
            }
        }

        if ($statusDefault) {
            $status = $columns->get('status');

            if (is_array($status)
                && ! $this->isPendingDefault($status['default'])) {
                $failures[] = "{$table}.status default";
            }
        }

        if (in_array(
            $schema->getConnection()->getDriverName(),
            ['mysql', 'mariadb'],
            true,
        )) {
            foreach ([
                'provider',
                'provider_message_id',
                'provider_event_id',
            ] as $columnName) {
                $column = $columns->get($columnName);

                if (is_array($column)
                    && ! str_ends_with(
                        strtolower((string) $column['collation']),
                        '_bin',
                    )) {
                    $failures[] = "{$table}.{$columnName} case sensitivity";
                }
            }
        }

        if ($failures !== []) {
            throw new LogicException(sprintf(
                'Existing %s table [%s] is incompatible with nvl/mail-notifications; incompatible definitions: %s. Configure fresh package tables or migrate the legacy schema before continuing.',
                $label,
                $table,
                implode(', ', $failures),
            ));
        }
    }

    /**
     * Require one exact named index, ordered column list, and uniqueness.
     *
     * @param  list<string>  $columns
     */
    private function hasNamedIndex(
        Builder $schema,
        string $table,
        string $name,
        array $columns,
        ?bool $unique = null,
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
        $columnsMatch = is_array($existingColumns)
            && count($existingColumns) === count($columns)
            && array_values(array_filter(
                $existingColumns,
                'is_string',
            )) === $columns;
        $uniquenessMatches = $unique === null
            || ($index['unique'] ?? null) === $unique;

        return $columnsMatch && $uniquenessMatches;
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
     * Return the exact delivery-status allowlist.
     *
     * @return list<string>
     */
    private function mailDeliveryStatuses(): array
    {
        return array_map(
            static fn (MailDeliveryStatus $status): string => $status->value,
            MailDeliveryStatus::cases(),
        );
    }

    /**
     * Determine whether a database type belongs to the portable family.
     */
    private function columnTypeMatches(string $typeName, string $family): bool
    {
        return match ($family) {
            'identity' => in_array($typeName, [
                'uuid',
                'char',
                'varchar',
                'character varying',
            ], true),
            'string' => in_array($typeName, [
                'char',
                'varchar',
                'character varying',
                'nvarchar',
            ], true),
            'text' => in_array($typeName, [
                'text',
                'longtext',
                'mediumtext',
            ], true),
            'json' => in_array($typeName, [
                'json',
                'jsonb',
                'text',
                'longtext',
            ], true),
            'timestamp' => in_array($typeName, [
                'datetime',
                'datetimeoffset',
                'timestamp',
                'timestamp with time zone',
                'timestamptz',
            ], true),
            default => false,
        };
    }

    /**
     * Determine whether the database exposes the pending status default.
     */
    private function isPendingDefault(mixed $default): bool
    {
        if (! is_string($default)) {
            return false;
        }

        return preg_match(
            '/^[\'"]?pending[\'"]?(?:::[a-z_ ]+)?$/i',
            trim($default),
        ) === 1;
    }

    /**
     * Determine whether events belong to notifications with cascade deletion.
     */
    private function hasOwnershipCascade(
        Builder $schema,
        string $notificationTable,
        string $eventTable,
    ): bool {
        return ForeignKeyInspector::hasOwnershipCascade(
            schema: $schema,
            ownerTable: $notificationTable,
            ownedTable: $eventTable,
            ownedColumn: 'mail_notification_id',
            ownerColumn: 'id',
        );
    }

    /**
     * Assert named schema constraints or operational indexes.
     *
     * @param  array<string, bool>  $checks
     */
    private function assertChecks(
        string $table,
        string $label,
        array $checks,
    ): void {
        $failed = array_keys(array_filter(
            $checks,
            static fn (bool $passed): bool => ! $passed,
        ));

        if ($failed === []) {
            return;
        }

        throw new LogicException(sprintf(
            'Existing %s table [%s] is incompatible with nvl/mail-notifications; missing: %s. Configure fresh package tables or migrate the legacy schema before continuing.',
            $label,
            $table,
            implode(', ', $failed),
        ));
    }
};
