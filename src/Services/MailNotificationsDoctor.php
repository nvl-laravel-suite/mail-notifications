<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Contracts\ProviderConfigurationValidator;
use Nvl\MailNotifications\Contracts\SensitiveDataRedactor;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Contracts\WebhookEventNormalizer;
use Nvl\MailNotifications\Contracts\WebhookSignatureVerifier;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\ForeignKeyInspector;
use Nvl\MailNotifications\Support\StatusConstraintDatabase;
use Nvl\MailNotifications\Support\StatusConstraintInspector;
use Nvl\MailNotifications\ValueObjects\MailNotificationsDoctorCheck;
use Throwable;

/**
 * Inspects package configuration and schema readiness without mutation.
 */
final readonly class MailNotificationsDoctor
{
    /**
     * Migrations that establish ownership of the package storage tables.
     *
     * @var list<string>
     */
    private const array SCHEMA_CREATOR_MIGRATIONS = [
        '2026_07_29_000000_create_mail_notification_tables',
        '2026_07_30_000100_create_scheduled_mail_messages_table',
    ];

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
     * Create the package readiness inspector.
     */
    public function __construct(
        private TrackingEligibility $eligibility,
        private SymfonyMessageIdResolver $messageIds,
        private ProviderRegistry $providers,
        private RemoteWebhookManagerRegistry $remoteWebhooks,
        private MailNotificationNotifiableTypeRegistry $notifiableTypes,
        private WebhookProcessor $webhooks,
        private TrackingLifecycle $lifecycle,
        private SensitiveDataRedactor $redactor,
        private MailTestingInterceptor $testing,
        private ScheduledMailReadiness $scheduledMail,
        private MailRetentionConfiguration $retention,
        private MailAnonymizationConfiguration $anonymization,
        private SensitiveStorageCodec $sensitiveStorage,
        private Migrator $migrator,
    ) {}

    /**
     * Return all package readiness checks.
     *
     * @return list<MailNotificationsDoctorCheck>
     */
    public function inspect(): array
    {
        return [
            $this->configurationCheck(),
            $this->sensitiveStorageConfigurationCheck(),
            $this->retentionConfigurationCheck(),
            $this->anonymizationConfigurationCheck(),
            $this->testing->inspect(),
            $this->migrationOwnershipCheck(),
            $this->migrationHistoryCheck(),
            ...$this->schemaChecks(),
            ...$this->scheduledMail->inspect(),
        ];
    }

    /**
     * Warn when automatic vendor loading overlaps a published migration copy.
     */
    private function migrationOwnershipCheck(): MailNotificationsDoctorCheck
    {
        $duplicates = config('mail-notifications.migrations.enabled') === true
            ? $this->publishedMigrationDuplicates(dirname(__DIR__, 2).'/database/migrations')
            : [];

        return new MailNotificationsDoctorCheck(
            key: 'schema.migration_ownership',
            severity: 'warning',
            passed: $duplicates === [],
            message: $duplicates === []
                ? 'Automatic vendor migration loading does not overlap a published host copy.'
                : sprintf(
                    'Automatic vendor migration loading overlaps published host migration(s): %s. Disable mail-notifications.migrations.enabled before running host-owned copies.',
                    implode(', ', $duplicates),
                ),
        );
    }

    /**
     * Find host migrations whose timestamp-independent names match package migrations.
     *
     * @return list<string>
     */
    private function publishedMigrationDuplicates(string $packagePath): array
    {
        $packageMigrations = glob($packagePath.'/*.php') ?: [];
        $hostMigrations = glob(database_path('migrations/*.php')) ?: [];
        $packageNames = array_map($this->migrationName(...), $packageMigrations);
        $duplicates = [];

        foreach ($hostMigrations as $migration) {
            $name = $this->migrationName($migration);

            if (in_array($name, $packageNames, true)) {
                $duplicates[] = $name;
            }
        }

        sort($duplicates);

        return array_values(array_unique($duplicates));
    }

    /**
     * Remove Laravel's timestamp prefix from a migration filename.
     */
    private function migrationName(string $path): string
    {
        return (string) preg_replace(
            '/^\d{4}_\d{2}_\d{2}_\d{6}_/',
            '',
            pathinfo($path, PATHINFO_FILENAME),
        );
    }

    /**
     * Verify enabled package migrations have exact repository ownership records.
     */
    private function migrationHistoryCheck(): MailNotificationsDoctorCheck
    {
        $enabled = config(
            'mail-notifications.migrations.enabled',
            true,
        );

        if (! is_bool($enabled)) {
            return new MailNotificationsDoctorCheck(
                key: 'schema.migrations',
                severity: 'error',
                passed: false,
                message: 'Package migration ownership setting [mail-notifications.migrations.enabled] must be an actual boolean.',
            );
        }

        if (! $enabled) {
            return new MailNotificationsDoctorCheck(
                key: 'schema.migrations',
                severity: 'error',
                passed: true,
                message: 'Package migrations are disabled; the host application owns migration history and the configured schema is inspected directly.',
            );
        }

        try {
            $migrationFiles = $this->migrator->getMigrationFiles(
                dirname(__DIR__, 2).'/database/migrations',
            );
            $expectedMigrations = array_keys($migrationFiles);

            if ($expectedMigrations === []) {
                return new MailNotificationsDoctorCheck(
                    key: 'schema.migrations',
                    severity: 'error',
                    passed: false,
                    message: 'Package migrations are enabled, but no package migration files are available.',
                );
            }

            if (! $this->migrator->repositoryExists()) {
                return new MailNotificationsDoctorCheck(
                    key: 'schema.migrations',
                    severity: 'error',
                    passed: false,
                    message: 'Package migrations are enabled, but the migration repository does not exist.',
                );
            }

            $ranMigrations = $this->migrator->getRepository()->getRan();
            $missingMigrations = array_values(array_diff(
                $expectedMigrations,
                $ranMigrations,
            ));
            $missingCreatorMigrations = array_values(array_intersect(
                self::SCHEMA_CREATOR_MIGRATIONS,
                $missingMigrations,
            ));
            $retainedCreatorMigrations = array_values(array_filter(
                $missingCreatorMigrations,
                $this->creatorOwnsExistingTable(...),
            ));
            $pendingCreatorMigrations = array_values(array_diff(
                $missingCreatorMigrations,
                $retainedCreatorMigrations,
            ));
            $allSchemaCreatorsPending = array_diff(
                self::SCHEMA_CREATOR_MIGRATIONS,
                $missingMigrations,
            ) === [];

            return new MailNotificationsDoctorCheck(
                key: 'schema.migrations',
                severity: 'error',
                passed: $missingMigrations === [],
                message: $missingMigrations === []
                    ? 'Every enabled package migration has an exact repository ownership record.'
                    : sprintf(
                        'Missing exact package migration ownership records: %s. %s',
                        implode(', ', $missingMigrations),
                        match (true) {
                            $retainedCreatorMigrations !== [] => sprintf(
                                'Retained tables owned by missing creator record(s) [%s] cannot be reclaimed by rerunning those migrations; restore only independently verified exact repository records, or disable package migrations and adopt the schema with host-owned migrations.',
                                implode(', ', $retainedCreatorMigrations),
                            ),
                            $missingCreatorMigrations === [] => 'Only read-only package compatibility preflight history is missing; run the pending package migrations to revalidate the creator-owned schema.',
                            $allSchemaCreatorsPending => 'No package tables or creator ownership records are present; run the pending package migrations, or disable package migrations and create the schema with host-owned migrations.',
                            $pendingCreatorMigrations !== [] => sprintf(
                                'Missing creator migration(s) [%s] own no existing configured tables; run the pending package migrations to complete this interrupted or incremental install.',
                                implode(', ', $pendingCreatorMigrations),
                            ),
                            default => 'Package tables are missing while one or more creator ownership records remain; restore the owned schema or reconcile independently verified history before migrating.',
                        },
                    ),
            );
        } catch (Throwable $exception) {
            return new MailNotificationsDoctorCheck(
                key: 'schema.migrations',
                severity: 'error',
                passed: false,
                message: 'Package migration history is unavailable: '.$exception->getMessage(),
            );
        }
    }

    /**
     * Determine whether any configured package table survives without history.
     */
    private function creatorOwnsExistingTable(string $migration): bool
    {
        $notification = new MailNotification;
        $event = new MailNotificationEvent;
        $scheduled = new ScheduledMailMessage;
        $schema = Schema::connection($notification->getConnectionName());

        return match ($migration) {
            '2026_07_29_000000_create_mail_notification_tables' => $schema
                ->hasTable($notification->getTable())
                || $schema->hasTable($event->getTable()),
            '2026_07_30_000100_create_scheduled_mail_messages_table' => $schema
                ->hasTable($scheduled->getTable()),
            default => false,
        };
    }

    /**
     * Validate the disabled plaintext default or active transformer round trip.
     */
    private function sensitiveStorageConfigurationCheck(): MailNotificationsDoctorCheck
    {
        try {
            $this->sensitiveStorage->assertReady();
            $enabled = config(
                'mail-notifications.privacy.sensitive_storage.enabled',
                false,
            ) === true;
            $configured = $this->sensitiveStorage
                ->hasConfiguredTransformer();

            return new MailNotificationsDoctorCheck(
                key: 'privacy.sensitive_storage',
                severity: 'error',
                passed: true,
                message: match (true) {
                    $enabled => 'Sensitive array storage is enabled and its transformer passed a protected round-trip readiness probe; previous keys or profiles must remain available while protected history is retained.',
                    $configured => 'Sensitive array storage writes are disabled, but the configured transformer passed a protected round-trip readiness probe and is ready for a later enablement; plaintext and legacy array history remain readable, while marked protected history fails closed.',
                    default => 'Sensitive array storage is disabled and no transformer is configured; plaintext and legacy array history remain readable, while marked protected history fails closed.',
                },
            );
        } catch (Throwable $exception) {
            return new MailNotificationsDoctorCheck(
                key: 'privacy.sensitive_storage',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    /**
     * Validate the separate bounded anonymization retention stage.
     */
    private function anonymizationConfigurationCheck(): MailNotificationsDoctorCheck
    {
        try {
            if (! $this->anonymization->enabled()) {
                return new MailNotificationsDoctorCheck(
                    key: 'retention.anonymization',
                    severity: 'error',
                    passed: true,
                    message: 'Mail history anonymization is disabled.',
                );
            }

            $notificationDays = $this->anonymization
                ->notificationRetentionDays();
            $notificationStatuses = $this->anonymization
                ->notificationStatuses();
            $batchSize = $this->anonymization->batchSize();
            $limit = $this->anonymization->limit();
            $scheduledEnabled = $this->anonymization
                ->scheduledMessageAnonymizationEnabled();

            if (! $scheduledEnabled) {
                return new MailNotificationsDoctorCheck(
                    key: 'retention.anonymization',
                    severity: 'error',
                    passed: true,
                    message: sprintf(
                        'Mail history anonymization is enabled at %d day(s) for %d notification status(es), batch size %d, and independent data-set limit %d; scheduled-message anonymization is disabled.',
                        $notificationDays,
                        count($notificationStatuses),
                        $batchSize,
                        $limit,
                    ),
                );
            }

            $scheduledDays = $this->anonymization
                ->scheduledMessageRetentionDays();
            $scheduledStatuses = $this->anonymization
                ->scheduledMessageStatuses();

            return new MailNotificationsDoctorCheck(
                key: 'retention.anonymization',
                severity: 'error',
                passed: true,
                message: sprintf(
                    'Mail history anonymization is enabled at %d day(s) for %d notification status(es), batch size %d, and independent data-set limit %d; scheduled-message anonymization is enabled at %d day(s) for %d terminal status(es).',
                    $notificationDays,
                    count($notificationStatuses),
                    $batchSize,
                    $limit,
                    $scheduledDays,
                    count($scheduledStatuses),
                ),
            );
        } catch (Throwable $exception) {
            return new MailNotificationsDoctorCheck(
                key: 'retention.anonymization',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    /**
     * Validate bounded retention settings without inspecting storage.
     */
    private function retentionConfigurationCheck(): MailNotificationsDoctorCheck
    {
        try {
            $notificationDays = $this->retention
                ->notificationRetentionDays();
            $notificationStatuses = $this->retention
                ->notificationStatuses();
            $batchSize = $this->retention->batchSize();
            $limit = $this->retention->limit();
            $scheduledEnabled = $this->retention
                ->scheduledMessagePruningEnabled();

            if (! $scheduledEnabled) {
                return new MailNotificationsDoctorCheck(
                    key: 'retention.configuration',
                    severity: 'error',
                    passed: true,
                    message: sprintf(
                        'Notification retention is valid at %d day(s) for %d status(es), batch size %d, and limit %d; scheduled-message retention is disabled.',
                        $notificationDays,
                        count($notificationStatuses),
                        $batchSize,
                        $limit,
                    ),
                );
            }

            $scheduledDays = $this->retention
                ->scheduledMessageRetentionDays();
            $scheduledStatuses = $this->retention
                ->scheduledMessageStatuses();

            return new MailNotificationsDoctorCheck(
                key: 'retention.configuration',
                severity: 'error',
                passed: true,
                message: sprintf(
                    'Notification retention is valid at %d day(s) for %d status(es), batch size %d, and limit %d; scheduled-message retention is enabled at %d day(s) for %d terminal status(es).',
                    $notificationDays,
                    count($notificationStatuses),
                    $batchSize,
                    $limit,
                    $scheduledDays,
                    count($scheduledStatuses),
                ),
            );
        } catch (Throwable $exception) {
            return new MailNotificationsDoctorCheck(
                key: 'retention.configuration',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    /**
     * Validate failure policy and mailer exclusions.
     */
    private function configurationCheck(): MailNotificationsDoctorCheck
    {
        try {
            $this->eligibility->enabled();
            $policy = $this->eligibility->failurePolicy();
            $excludedMailers = $this->eligibility->excludedMailers();
            $this->messageIds->validateConfiguration();
            $webhooksEnabled = $this->webhooks->enabled();

            foreach ($this->providers->all() as $provider) {
                if ($provider instanceof ProviderConfigurationValidator) {
                    $provider->validateConfiguration($webhooksEnabled);
                }
            }

            $providerCount = count($this->providers->all());
            $enabledRemoteWebhookManagers = 0;

            foreach ($this->remoteWebhooks->all() as $manager) {
                if (! $manager->enabled()) {
                    continue;
                }

                $manager->validateConfiguration();
                $adapter = $this->providers->all()[$manager->provider()] ?? null;

                if (! $adapter instanceof WebhookSignatureVerifier
                    || ! $adapter instanceof WebhookEventNormalizer) {
                    throw new MailTrackingException(sprintf(
                        'Enabled remote webhook manager [%s] requires a same-name provider adapter with verification and normalization capabilities.',
                        $manager->provider(),
                    ));
                }

                $enabledRemoteWebhookManagers++;
            }

            $notifiableTypeCount = count($this->notifiableTypes->all());
            $maximumPayloadBytes = $this->webhooks->maximumPayloadBytes();
            $this->webhooks->unknownEventPolicy();
            $this->webhooks->unmatchedEventPolicy();
            $this->webhooks->unmatchedEventRetryGraceSeconds();
            $this->webhooks->allowedContentTypes();
            $webhookStatus = $webhooksEnabled ? 'enabled' : 'disabled';
            $this->redactor->redact([]);

            return new MailNotificationsDoctorCheck(
                key: 'configuration',
                severity: 'error',
                passed: true,
                message: sprintf(
                    'Failure policy [%s] is valid; %d mailer(s) are excluded; %d provider adapter(s), %d enabled remote webhook manager(s), and %d notifiable type(s) are registered; webhooks are %s with payloads limited to %d bytes; lifecycle [%s] and redactor [%s] are active.',
                    $policy->value,
                    count($excludedMailers),
                    $providerCount,
                    $enabledRemoteWebhookManagers,
                    $notifiableTypeCount,
                    $webhookStatus,
                    $maximumPayloadBytes,
                    $this->lifecycle::class,
                    $this->redactor::class,
                ),
            );
        } catch (Throwable $exception) {
            return new MailNotificationsDoctorCheck(
                key: 'configuration',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    /**
     * Inspect the package-owned tracking tables and required columns.
     *
     * @return list<MailNotificationsDoctorCheck>
     */
    private function schemaChecks(): array
    {
        $notification = new MailNotification;
        $event = new MailNotificationEvent;

        try {
            $schema = Schema::connection($notification->getConnectionName());
            $notificationExists = $schema->hasTable($notification->getTable());
            $eventExists = $schema->hasTable($event->getTable());
        } catch (Throwable $exception) {
            return [new MailNotificationsDoctorCheck(
                key: 'schema.connection',
                severity: 'error',
                passed: false,
                message: 'The configured mail tracking database is unavailable: '.$exception->getMessage(),
            )];
        }

        $checks = [
            $this->databaseSupportCheck($schema),
            new MailNotificationsDoctorCheck(
                key: 'schema.notifications',
                severity: 'error',
                passed: $notificationExists,
                message: $notificationExists
                    ? "Table [{$notification->getTable()}] exists."
                    : "Table [{$notification->getTable()}] is missing.",
            ),
            new MailNotificationsDoctorCheck(
                key: 'schema.events',
                severity: 'error',
                passed: $eventExists,
                message: $eventExists
                    ? "Table [{$event->getTable()}] exists."
                    : "Table [{$event->getTable()}] is missing.",
            ),
        ];

        if (! $notificationExists || ! $eventExists) {
            return $checks;
        }

        try {
            return [
                ...$checks,
                ...$this->schemaStructureChecks($schema, $notification, $event),
            ];
        } catch (Throwable $exception) {
            $checks[] = new MailNotificationsDoctorCheck(
                key: 'schema.introspection',
                severity: 'error',
                passed: false,
                message: 'Mail tracking schema introspection failed: '.$exception->getMessage(),
            );

            return $checks;
        }
    }

    /**
     * Inspect columns, identity and status constraints, and operational indexes.
     *
     * @return list<MailNotificationsDoctorCheck>
     */
    private function schemaStructureChecks(
        Builder $schema,
        MailNotification $notification,
        MailNotificationEvent $event,
    ): array {
        $missingNotificationColumns = $this->missingColumns(
            $schema,
            $notification->getTable(),
            self::NOTIFICATION_COLUMNS,
        );
        $missingEventColumns = $this->missingColumns(
            $schema,
            $event->getTable(),
            self::EVENT_COLUMNS,
        );
        $missingColumns = [
            ...$missingNotificationColumns,
            ...$missingEventColumns,
        ];
        $invalidDefinitions = [
            ...$this->invalidColumnDefinitions(
                schema: $schema,
                table: $notification->getTable(),
                typeFamilies: self::NOTIFICATION_COLUMN_TYPES,
                nullableColumns: self::NULLABLE_NOTIFICATION_COLUMNS,
                lengths: self::NOTIFICATION_COLUMN_LENGTHS,
                statusDefault: true,
            ),
            ...$this->invalidColumnDefinitions(
                schema: $schema,
                table: $event->getTable(),
                typeFamilies: self::EVENT_COLUMN_TYPES,
                nullableColumns: self::NULLABLE_EVENT_COLUMNS,
                lengths: self::EVENT_COLUMN_LENGTHS,
            ),
        ];
        $deliveryStatuses = array_map(
            static fn (MailDeliveryStatus $status): string => $status->value,
            MailDeliveryStatus::cases(),
        );
        $constraintChecks = [
            'notification primary key' => $schema->hasIndex(
                $notification->getTable(),
                ['id'],
                'primary',
            ),
            'notification correlation identity' => $schema->hasIndex(
                $notification->getTable(),
                ['correlation_id'],
                'unique',
            ),
            'provider message identity' => $schema->hasIndex(
                $notification->getTable(),
                ['provider', 'provider_message_id'],
                'unique',
            ),
            'provider event identity' => $schema->hasIndex(
                $event->getTable(),
                ['provider', 'provider_event_id'],
                'unique',
            ),
            'notification status allowlist' => StatusConstraintInspector::matches(
                connection: $schema->getConnection(),
                table: $notification->getTable(),
                column: 'status',
                constraint: 'mail_notifications_status_check',
                allowedValues: $deliveryStatuses,
            ),
            'provider event status allowlist' => StatusConstraintInspector::matches(
                connection: $schema->getConnection(),
                table: $event->getTable(),
                column: 'normalized_type',
                constraint: 'mail_notification_events_normalized_type_check',
                allowedValues: $deliveryStatuses,
            ),
            'provider event ownership cascade' => ForeignKeyInspector::hasOwnershipCascade(
                schema: $schema,
                ownerTable: $notification->getTable(),
                ownedTable: $event->getTable(),
                ownedColumn: 'mail_notification_id',
                ownerColumn: 'id',
            ),
        ];
        $indexChecks = [
            'notifiable timeline lookup' => $schema->hasIndex(
                $notification->getTable(),
                ['notifiable_type', 'notifiable_id', 'created_at'],
            ),
            'status timeline lookup' => $schema->hasIndex(
                $notification->getTable(),
                ['status', 'created_at'],
            ),
            'status-change retention lookup' => $schema->hasIndex(
                $notification->getTable(),
                ['status', 'status_changed_at'],
            ),
            'recipient timeline lookup' => $schema->hasIndex(
                $notification->getTable(),
                ['primary_recipient_email', 'created_at'],
            ),
            'queued failure lookup' => $schema->hasIndex(
                $notification->getTable(),
                ['queue_reference', 'created_at'],
            ),
            'provider event timeline lookup' => $schema->hasIndex(
                $event->getTable(),
                ['mail_notification_id', 'occurred_at'],
            ),
            'notification privacy-stage lookup' => $schema->hasIndex(
                $notification->getTable(),
                [
                    'redacted_at',
                    'status',
                    'status_changed_at',
                    'id',
                ],
            ),
            'provider event privacy-stage lookup' => $schema->hasIndex(
                $event->getTable(),
                ['redacted_at', 'occurred_at', 'id'],
            ),
        ];
        $missingConstraints = $this->failedLabels($constraintChecks);
        $missingIndexes = $this->failedLabels($indexChecks);

        return [
            new MailNotificationsDoctorCheck(
                key: 'schema.columns',
                severity: 'error',
                passed: $missingColumns === [],
                message: $missingColumns === []
                    ? 'Required mail tracking columns exist.'
                    : 'Missing columns: '.implode(', ', $missingColumns).'.',
            ),
            new MailNotificationsDoctorCheck(
                key: 'schema.constraints',
                severity: 'error',
                passed: $missingConstraints === [],
                message: $missingConstraints === []
                    ? 'Required mail tracking identity and status constraints exist.'
                    : 'Missing constraints: '.implode(', ', $missingConstraints).'.',
            ),
            new MailNotificationsDoctorCheck(
                key: 'schema.definitions',
                severity: 'error',
                passed: $invalidDefinitions === [],
                message: $invalidDefinitions === []
                    ? 'Mail tracking column types, lengths, nullability, defaults, and identity collations are compatible.'
                    : 'Incompatible column definitions: '.implode(', ', $invalidDefinitions).'.',
            ),
            new MailNotificationsDoctorCheck(
                key: 'schema.indexes',
                severity: 'warning',
                passed: $missingIndexes === [],
                message: $missingIndexes === []
                    ? 'Recommended mail tracking query indexes exist.'
                    : 'Missing indexes: '.implode(', ', $missingIndexes).'.',
            ),
            $this->databaseTimezoneCheck($schema),
        ];
    }

    /**
     * Verify the configured database can enforce exact status allowlists.
     */
    private function databaseSupportCheck(
        Builder $schema,
    ): MailNotificationsDoctorCheck {
        $connection = $schema->getConnection();
        $reason = StatusConstraintDatabase::unsupportedReason($connection);

        return new MailNotificationsDoctorCheck(
            key: 'schema.database',
            severity: 'error',
            passed: $reason === null,
            message: $reason ?? sprintf(
                'Database driver [%s] supports enforceable mail notification status invariants.',
                $connection->getDriverName(),
            ),
        );
    }

    /**
     * Verify the database session preserves package UTC timestamp semantics.
     */
    private function databaseTimezoneCheck(
        Builder $schema,
    ): MailNotificationsDoctorCheck {
        $connection = $schema->getConnection();
        $driver = $connection->getDriverName();
        $passed = match ($driver) {
            'sqlite' => true,
            'pgsql' => $this->postgresUsesUtc($connection->scalar(
                "select current_setting('TIMEZONE')",
            )),
            'mysql', 'mariadb' => $this->mysqlUsesUtc($connection->scalar(
                'select timestampdiff(second, utc_timestamp(), now())',
            )),
            default => false,
        };

        return new MailNotificationsDoctorCheck(
            key: 'schema.timezone',
            severity: 'error',
            passed: $passed,
            message: $passed
                ? "Database driver [{$driver}] preserves UTC tracking timestamps."
                : "Database driver [{$driver}] must use a UTC session timezone for tracking.",
        );
    }

    /**
     * Determine whether a PostgreSQL session reports a UTC-equivalent zone.
     */
    private function postgresUsesUtc(mixed $result): bool
    {
        $timezone = is_string($result)
            ? strtoupper(trim($result))
            : '';

        return in_array($timezone, [
            'UTC',
            'ETC/UTC',
            'GMT',
            'UCT',
            'ZULU',
        ], true);
    }

    /**
     * Determine whether a MySQL-family session has zero UTC offset.
     */
    private function mysqlUsesUtc(mixed $result): bool
    {
        return is_int($result) && $result === 0
            || is_string($result) && is_numeric($result) && (int) $result === 0;
    }

    /**
     * Return required columns that are absent from one table.
     *
     * @param  list<string>  $requiredColumns
     * @return list<string>
     */
    private function missingColumns(
        Builder $schema,
        string $table,
        array $requiredColumns,
    ): array {
        return array_values(array_filter(
            $requiredColumns,
            static fn (string $column): bool => ! $schema->hasColumn(
                $table,
                $column,
            ),
        ));
    }

    /**
     * Return incompatible type, length, nullability, default, or collation labels.
     *
     * @param  array<string, string>  $typeFamilies
     * @param  list<string>  $nullableColumns
     * @param  array<string, int>  $lengths
     * @return list<string>
     */
    private function invalidColumnDefinitions(
        Builder $schema,
        string $table,
        array $typeFamilies,
        array $nullableColumns,
        array $lengths,
        bool $statusDefault = false,
    ): array {
        $columns = collect($schema->getColumns($table))->keyBy(
            static fn (array $column): string => strtolower((string) $column['name']),
        );
        $failures = [];

        foreach ($typeFamilies as $columnName => $typeFamily) {
            $column = $columns->get($columnName);

            if (! is_array($column)) {
                continue;
            }

            $label = "{$table}.{$columnName}";
            $typeName = strtolower($column['type_name']);

            if (! $this->columnTypeMatches($typeName, $typeFamily)) {
                $failures[] = "{$label} type";
            }

            $expectedNullable = in_array(
                $columnName,
                $nullableColumns,
                true,
            );

            if ($column['nullable'] !== $expectedNullable) {
                $failures[] = "{$label} nullability";
            }

            $expectedLength = $lengths[$columnName] ?? null;
            $declaredType = strtolower($column['type']);

            if ($expectedLength !== null
                && preg_match('/\((\d+)\)/', $declaredType, $matches) === 1
                && (int) $matches[1] !== $expectedLength) {
                $failures[] = "{$label} length";
            }
        }

        if ($statusDefault) {
            $status = $columns->get('status');

            if (is_array($status)
                && ! $this->isPendingDefault($status['default'] ?? null)) {
                $failures[] = "{$table}.status default";
            }
        }

        if (in_array(
            $schema->getConnection()->getDriverName(),
            ['mysql', 'mariadb'],
            true,
        )) {
            foreach (['provider', 'provider_message_id', 'provider_event_id'] as $columnName) {
                $column = $columns->get($columnName);

                if (is_array($column)
                    && ! str_ends_with(
                        strtolower((string) ($column['collation'] ?? '')),
                        '_bin',
                    )) {
                    $failures[] = "{$table}.{$columnName} case sensitivity";
                }
            }
        }

        return $failures;
    }

    /**
     * Determine whether a database type belongs to the required portable family.
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
     * Determine whether the database exposes the required pending status default.
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
     * Return labels for failed schema assertions.
     *
     * @param  array<string, bool>  $checks
     * @return list<string>
     */
    private function failedLabels(array $checks): array
    {
        return array_keys(array_filter(
            $checks,
            static fn (bool $passed): bool => ! $passed,
        ));
    }
}
