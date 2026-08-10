<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Actions;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\MailNotificationsAdoptionManifest;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Support\CanonicalMailNotificationSchema;
use Nvl\MailNotifications\ValueObjects\LegacyMailForeignKey;
use Nvl\MailNotifications\ValueObjects\LegacyMailNotificationMapping;
use Nvl\MailNotifications\ValueObjects\LegacyScheduledMailMapping;
use Nvl\MailNotifications\ValueObjects\MailNotificationsAdoptionPlan;
use RuntimeException;
use stdClass;

/**
 * Plans, stages, and atomically imports one bounded legacy mail data set.
 */
final readonly class AdoptMailNotificationsAction
{
    public function __construct(
        private MailNotificationsAdoptionManifest $manifests,
        private MailNotificationNotifiableTypeRegistry $notifiableTypes,
        private ScheduledMessageFactoryRegistry $factories,
    ) {}

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function execute(
        array $manifest,
        bool $stage = false,
        bool $apply = false,
    ): array {
        $plan = $this->manifests->normalize($manifest);
        $this->assertStorageConnection($plan);

        return $stage
            ? $this->stage($plan, $apply)
            : $this->adopt($plan, $apply);
    }

    /**
     * @return array<string, mixed>
     */
    private function stage(MailNotificationsAdoptionPlan $plan, bool $apply): array
    {
        if ($plan->stages === []) {
            throw new InvalidArgumentException(
                'Mail Notifications staging requires at least one table rename.',
            );
        }

        $schema = Schema::connection($plan->connection);

        foreach ($plan->stages as $stage) {
            if (! $schema->hasTable($stage->sourceTable)) {
                throw new InvalidArgumentException(
                    "Mail Notifications staging source [{$stage->sourceTable}] does not exist.",
                );
            }

            if ($schema->hasTable($stage->stagingTable)) {
                throw new InvalidArgumentException(
                    "Mail Notifications staging target [{$stage->stagingTable}] already exists.",
                );
            }
        }

        $detached = [];

        foreach ($plan->foreignKeys as $foreignKey) {
            $this->assertHostForeignKeyTable($schema, $foreignKey);

            if ($this->hasForeignKey($schema->getForeignKeys($foreignKey->table), $foreignKey)) {
                $detached[] = $foreignKey->name;
            }
        }

        if ($apply) {
            foreach ($plan->foreignKeys as $foreignKey) {
                if ($this->hasForeignKey($schema->getForeignKeys($foreignKey->table), $foreignKey)) {
                    $schema->table(
                        $foreignKey->table,
                        static fn (Blueprint $table) => $table->dropForeign([$foreignKey->column]),
                    );
                }
            }

            foreach ($plan->stages as $stage) {
                $schema->rename($stage->sourceTable, $stage->stagingTable);
            }
        }

        return [
            'phase' => 'stage',
            'mode' => $apply ? 'apply' : 'plan',
            'renames' => array_map(
                static fn ($stage): array => [
                    'source' => $stage->sourceTable,
                    'staging' => $stage->stagingTable,
                ],
                $plan->stages,
            ),
            'foreign_keys_detached' => $apply ? $detached : [],
            'next' => 'Run package migrations, then rerun without --stage to validate or apply the import.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adopt(MailNotificationsAdoptionPlan $plan, bool $apply): array
    {
        $this->assertCanonicalSchema($plan);

        if ((bool) config('mail-notifications.privacy.sensitive_storage.enabled', false)) {
            throw new InvalidArgumentException(
                'Disable Mail Notifications sensitive storage until legacy adoption is complete.',
            );
        }

        [$notifications, $events] = $plan->notifications instanceof LegacyMailNotificationMapping
            ? $this->prepareNotifications($plan, $plan->notifications)
            : [[], []];
        $scheduled = $plan->scheduledMessages instanceof LegacyScheduledMailMapping
            ? $this->prepareScheduled($plan, $plan->scheduledMessages)
            : [];
        $expectedNotifications = $plan->notifications instanceof LegacyMailNotificationMapping
            ? $plan->notifications->expectedCount
            : 0;
        $expectedScheduled = $plan->scheduledMessages instanceof LegacyScheduledMailMapping
            ? $plan->scheduledMessages->expectedCount
            : 0;
        $this->assertTargetIdentitiesAvailable($plan, $notifications, $scheduled);

        if ($apply) {
            $connection = DB::connection($plan->connection);
            $connection->transaction(function () use (
                $connection,
                $notifications,
                $events,
                $scheduled,
            ): void {
                $this->insertChunks(
                    $connection,
                    (new MailNotification)->getTable(),
                    $notifications,
                );
                $this->insertChunks(
                    $connection,
                    (new MailNotificationEvent)->getTable(),
                    $events,
                );
                $this->insertChunks(
                    $connection,
                    (new ScheduledMailMessage)->getTable(),
                    $scheduled,
                );
            });
            $this->reconcile($plan, $notifications, $events, $scheduled);
            $this->restoreForeignKeys($plan);

            if ($plan->dropSources) {
                $this->dropSources($plan);
            }
        }

        return [
            'phase' => 'adoption',
            'mode' => $apply ? 'apply' : 'plan',
            'reconciliation' => [
                'notifications' => [
                    'expected' => $expectedNotifications,
                    'source' => count($notifications),
                    'imported' => $apply ? count($notifications) : 0,
                    'matched' => $apply ? count($notifications) : 0,
                ],
                'events' => [
                    'generated' => count($events),
                    'imported' => $apply ? count($events) : 0,
                    'matched' => $apply ? count($events) : 0,
                ],
                'scheduled_messages' => [
                    'expected' => $expectedScheduled,
                    'source' => count($scheduled),
                    'imported' => $apply ? count($scheduled) : 0,
                    'matched' => $apply ? count($scheduled) : 0,
                ],
            ],
            'foreign_keys_restored' => $apply ? count($plan->foreignKeys) : 0,
            'sources_dropped' => $apply && $plan->dropSources
                ? $this->sourceTables($plan)
                : [],
        ];
    }

    private function assertStorageConnection(MailNotificationsAdoptionPlan $plan): void
    {
        $configured = (new MailNotification)->getConnectionName()
            ?? config('database.default');
        $source = $plan->connection ?? config('database.default');

        if ($configured !== $source) {
            throw new InvalidArgumentException(
                'Mail Notifications adoption currently requires source and package storage on the same connection.',
            );
        }
    }

    private function assertCanonicalSchema(MailNotificationsAdoptionPlan $plan): void
    {
        $schema = Schema::connection($plan->connection);
        $tables = [
            (new MailNotification)->getTable() => CanonicalMailNotificationSchema::notifications(),
            (new MailNotificationEvent)->getTable() => CanonicalMailNotificationSchema::events(),
            (new ScheduledMailMessage)->getTable() => CanonicalMailNotificationSchema::scheduled(),
        ];

        foreach ($tables as $table => $columns) {
            if (! $schema->hasTable($table)) {
                throw new InvalidArgumentException(
                    "Canonical Mail Notifications table [{$table}] does not exist.",
                );
            }

            foreach ($columns as $column) {
                if (! $schema->hasColumn($table, $column)) {
                    throw new InvalidArgumentException(
                        "Canonical Mail Notifications table [{$table}] is missing [{$column}].",
                    );
                }
            }
        }
    }

    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function prepareNotifications(
        MailNotificationsAdoptionPlan $plan,
        LegacyMailNotificationMapping $mapping,
    ): array {
        $sourceColumns = $mapping->columns;

        foreach ($mapping->eventTimestamps as $type => $column) {
            $sourceColumns["event_{$type}"] = $column;
        }

        $rows = $this->sourceRows($plan, $mapping->table, $sourceColumns);

        if ($rows->count() !== $mapping->expectedCount) {
            throw new InvalidArgumentException(sprintf(
                'Mail Notifications adoption expected %d notification rows but found %d.',
                $mapping->expectedCount,
                $rows->count(),
            ));
        }

        $notifications = [];
        $events = [];
        $seen = [];

        foreach ($rows as $row) {
            $id = $this->requiredString($this->value($row, $mapping->columns, 'id'), 'notification id');

            if (! Str::isUuid($id) || isset($seen[$id])) {
                throw new InvalidArgumentException(
                    "Mail Notifications adoption notification identity [{$id}] is invalid or duplicated.",
                );
            }

            $seen[$id] = true;
            $legacyStatus = $this->requiredString(
                $this->value($row, $mapping->columns, 'status'),
                "notification {$id} status",
            );
            $status = $mapping->statuses[$legacyStatus] ?? null;

            if ($status === null) {
                throw new InvalidArgumentException(
                    "Mail Notifications adoption status [{$legacyStatus}] has no explicit mapping.",
                );
            }

            $mailer = $this->boundedRequiredString(
                $this->value($row, $mapping->columns, 'mailer'),
                128,
                "notification {$id} mailer",
            );
            $provider = $this->boundedNullableString(
                $this->value($row, $mapping->columns, 'provider'),
                128,
            ) ?? $mailer;
            [$notifiableType, $notifiableId] = $this->notifiable(
                row: $row,
                columns: $mapping->columns,
                aliases: $mapping->notifiableTypes,
                label: "notification {$id}",
            );
            $updatedAt = $this->value($row, $mapping->columns, 'updated_at');
            $statusChangedAt = $this->value($row, $mapping->columns, 'status_changed_at')
                ?? $updatedAt;
            $correlationId = $this->nullableString(
                $this->value($row, $mapping->columns, 'correlation_id'),
            ) ?? $id;

            if (! Str::isUuid($correlationId)) {
                throw new InvalidArgumentException(
                    "Mail Notifications adoption correlation identity [{$correlationId}] is not a UUID.",
                );
            }

            $notifications[] = [
                'id' => $id,
                'correlation_id' => $correlationId,
                'queue_reference' => $this->uuidOrNull(
                    $this->value($row, $mapping->columns, 'queue_reference'),
                    "notification {$id} queue reference",
                ),
                'mailer' => $mailer,
                'provider' => $provider,
                'provider_message_id' => $this->boundedNullableString(
                    $this->value($row, $mapping->columns, 'provider_message_id'),
                    255,
                ),
                'status' => $status,
                'message_category' => $this->boundedRequiredString(
                    $this->value($row, $mapping->columns, 'message_category'),
                    128,
                    "notification {$id} message category",
                ),
                'subject' => $this->nullableString($this->value($row, $mapping->columns, 'subject')),
                'from_email' => $this->boundedNullableString(
                    $this->value($row, $mapping->columns, 'from_email'),
                    254,
                ),
                'from_name' => $this->nullableString($this->value($row, $mapping->columns, 'from_name')),
                'to_recipients' => $this->encodedList(
                    $this->value($row, $mapping->columns, 'to_recipients'),
                    required: true,
                ),
                'cc_recipients' => $this->encodedList(
                    $this->value($row, $mapping->columns, 'cc_recipients'),
                ),
                'bcc_recipients' => $this->encodedList(
                    $this->value($row, $mapping->columns, 'bcc_recipients'),
                ),
                'primary_recipient_email' => $this->boundedNullableString(
                    $this->value($row, $mapping->columns, 'primary_recipient_email'),
                    254,
                ),
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiableId,
                'metadata' => $this->safeMetadata(
                    $this->value($row, $mapping->columns, 'metadata'),
                    $mapping->metadataAllowlist,
                ),
                'accepted_at' => $this->value($row, $mapping->columns, 'accepted_at'),
                'delivered_at' => $this->value($row, $mapping->columns, 'delivered_at'),
                'failed_at' => $this->value($row, $mapping->columns, 'failed_at'),
                'status_changed_at' => $statusChangedAt,
                'provider_occurred_at' => $status === MailDeliveryStatus::Pending->value
                    ? null
                    : ($this->value($row, $mapping->columns, 'provider_occurred_at') ?? $statusChangedAt),
                'redacted_at' => $this->value($row, $mapping->columns, 'redacted_at'),
                'created_at' => $this->value($row, $mapping->columns, 'created_at'),
                'updated_at' => $updatedAt,
            ];

            foreach ($mapping->eventTimestamps as $type => $column) {
                $occurredAt = $row->{$column} ?? null;

                if ($occurredAt === null) {
                    continue;
                }

                $events[] = [
                    'id' => (string) Str::uuid(),
                    'mail_notification_id' => $id,
                    'provider' => $provider,
                    'provider_event_id' => Str::limit("nvl-adopt:{$id}:{$type}", 255, ''),
                    'provider_message_id' => $this->boundedNullableString(
                        $this->value($row, $mapping->columns, 'provider_message_id'),
                        255,
                    ),
                    'normalized_type' => $type,
                    'occurred_at' => $occurredAt,
                    'metadata' => json_encode(
                        ['imported_from' => 'mail_notifications_adoption_v1'],
                        JSON_THROW_ON_ERROR,
                    ),
                    'processed_at' => $occurredAt,
                    'redacted_at' => null,
                    'created_at' => $this->value($row, $mapping->columns, 'created_at'),
                    'updated_at' => $updatedAt,
                ];
            }
        }

        return [$notifications, $events];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function prepareScheduled(
        MailNotificationsAdoptionPlan $plan,
        LegacyScheduledMailMapping $mapping,
    ): array {
        $rows = $this->sourceRows($plan, $mapping->table, $mapping->columns);

        if ($rows->count() !== $mapping->expectedCount) {
            throw new InvalidArgumentException(sprintf(
                'Mail Notifications adoption expected %d scheduled rows but found %d.',
                $mapping->expectedCount,
                $rows->count(),
            ));
        }

        $scheduled = [];
        $seen = [];

        foreach ($rows as $row) {
            $id = $this->requiredString($this->value($row, $mapping->columns, 'id'), 'scheduled id');

            if (! Str::isUuid($id) || isset($seen[$id])) {
                throw new InvalidArgumentException(
                    "Mail Notifications adoption scheduled identity [{$id}] is invalid or duplicated.",
                );
            }

            $seen[$id] = true;
            $factoryKey = $this->requiredString(
                $this->value($row, $mapping->columns, 'factory_key'),
                "scheduled {$id} factory key",
            );
            $factoryMapping = $mapping->factories[$factoryKey] ?? null;

            if ($factoryMapping === null) {
                throw new InvalidArgumentException(
                    "Mail Notifications adoption scheduled factory [{$factoryKey}] has no explicit mapping.",
                );
            }

            $payload = $this->decodedObject(
                $this->value($row, $mapping->columns, 'payload'),
                "scheduled {$id} payload",
            );
            $this->factories
                ->resolve($factoryMapping['alias'], $factoryMapping['version'])
                ->validate($factoryMapping['version'], $payload);
            $legacyStatus = $this->requiredString(
                $this->value($row, $mapping->columns, 'status'),
                "scheduled {$id} status",
            );
            $status = $mapping->statuses[$legacyStatus] ?? null;

            if ($status === null) {
                throw new InvalidArgumentException(
                    "Mail Notifications adoption scheduled status [{$legacyStatus}] has no explicit mapping.",
                );
            }

            [$notifiableType, $notifiableId] = $this->notifiable(
                row: $row,
                columns: $mapping->columns,
                aliases: $mapping->notifiableTypes,
                label: "scheduled {$id}",
            );
            $scheduledFor = $this->value($row, $mapping->columns, 'scheduled_for');
            $attempts = $this->nonNegativeInteger(
                $this->value($row, $mapping->columns, 'attempts'),
                0,
                "scheduled {$id} attempts",
            );
            $maxAttempts = $this->positiveInteger(
                $this->value($row, $mapping->columns, 'max_attempts'),
                $this->configuredMaximumAttempts(),
                "scheduled {$id} max attempts",
            );

            if ($attempts > $maxAttempts) {
                throw new InvalidArgumentException(
                    "Mail Notifications adoption scheduled {$id} attempts exceed max attempts.",
                );
            }

            $scheduled[] = [
                'id' => $id,
                'factory_alias' => $factoryMapping['alias'],
                'payload_version' => $factoryMapping['version'],
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'to_recipients' => $this->encodedList(
                    $this->value($row, $mapping->columns, 'to_recipients'),
                    required: true,
                ),
                'cc_recipients' => $this->encodedList(
                    $this->value($row, $mapping->columns, 'cc_recipients'),
                ),
                'bcc_recipients' => $this->encodedList(
                    $this->value($row, $mapping->columns, 'bcc_recipients'),
                ),
                'status' => $status,
                'scheduled_for' => $scheduledFor,
                'available_at' => $this->value($row, $mapping->columns, 'available_at')
                    ?? $scheduledFor,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'last_attempt_at' => $this->value($row, $mapping->columns, 'last_attempt_at'),
                'claim_token' => null,
                'locked_until' => null,
                'last_error' => Str::limit(
                    $this->nullableString($this->value($row, $mapping->columns, 'last_error_code')) ?? '',
                    255,
                    '',
                ) ?: null,
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiableId,
                'metadata' => $this->safeMetadata(
                    $this->value($row, $mapping->columns, 'metadata'),
                    $mapping->metadataAllowlist,
                ),
                'sent_at' => $this->value($row, $mapping->columns, 'sent_at'),
                'failed_at' => $this->value($row, $mapping->columns, 'failed_at'),
                'cancelled_at' => $this->value($row, $mapping->columns, 'cancelled_at'),
                'redacted_at' => $this->value($row, $mapping->columns, 'redacted_at'),
                'created_at' => $this->value($row, $mapping->columns, 'created_at'),
                'updated_at' => $this->value($row, $mapping->columns, 'updated_at'),
            ];
        }

        return $scheduled;
    }

    /**
     * @param  array<string, string>  $columns
     * @return Collection<int, stdClass>
     */
    private function sourceRows(
        MailNotificationsAdoptionPlan $plan,
        string $table,
        array $columns,
    ): Collection {
        $schema = Schema::connection($plan->connection);

        if (! $schema->hasTable($table)) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption source table [{$table}] does not exist.",
            );
        }

        foreach (array_unique($columns) as $column) {
            if (! $schema->hasColumn($table, $column)) {
                throw new InvalidArgumentException(
                    "Mail Notifications adoption source [{$table}] is missing [{$column}].",
                );
            }
        }

        return DB::connection($plan->connection)
            ->table($table)
            ->orderBy($columns['id'])
            ->get();
    }

    /**
     * @param  list<array<string, mixed>>  $notifications
     * @param  list<array<string, mixed>>  $scheduled
     */
    private function assertTargetIdentitiesAvailable(
        MailNotificationsAdoptionPlan $plan,
        array $notifications,
        array $scheduled,
    ): void {
        $connection = DB::connection($plan->connection);
        $targets = [
            (new MailNotification)->getTable() => array_column($notifications, 'id'),
            (new ScheduledMailMessage)->getTable() => array_column($scheduled, 'id'),
        ];

        foreach ($targets as $table => $identities) {
            foreach (array_chunk($identities, 500) as $chunk) {
                if ($connection->table($table)->whereIn('id', $chunk)->exists()) {
                    throw new InvalidArgumentException(
                        "Mail Notifications adoption target [{$table}] already contains a source identity.",
                    );
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $notifications
     * @param  list<array<string, mixed>>  $events
     * @param  list<array<string, mixed>>  $scheduled
     */
    private function reconcile(
        MailNotificationsAdoptionPlan $plan,
        array $notifications,
        array $events,
        array $scheduled,
    ): void {
        $connection = DB::connection($plan->connection);
        $targets = [
            (new MailNotification)->getTable() => array_column($notifications, 'id'),
            (new MailNotificationEvent)->getTable() => array_column($events, 'id'),
            (new ScheduledMailMessage)->getTable() => array_column($scheduled, 'id'),
        ];

        foreach ($targets as $table => $identities) {
            $matched = 0;

            foreach (array_chunk($identities, 500) as $chunk) {
                $matched += $connection->table($table)->whereIn('id', $chunk)->count();
            }

            if ($matched !== count($identities)) {
                throw new RuntimeException(sprintf(
                    'Mail Notifications adoption reconciliation matched %d of %d rows in [%s].',
                    $matched,
                    count($identities),
                    $table,
                ));
            }
        }
    }

    private function restoreForeignKeys(MailNotificationsAdoptionPlan $plan): void
    {
        $schema = Schema::connection($plan->connection);
        $target = (new MailNotification)->getTable();

        foreach ($plan->foreignKeys as $foreignKey) {
            $this->assertHostForeignKeyTable($schema, $foreignKey);

            if ($this->hasForeignKey($schema->getForeignKeys($foreignKey->table), $foreignKey)) {
                continue;
            }

            $missing = DB::connection($plan->connection)
                ->table($foreignKey->table)
                ->whereNotNull($foreignKey->column)
                ->whereNotExists(static function (QueryBuilder $query) use ($foreignKey, $target): void {
                    $query->selectRaw('1')
                        ->from($target)
                        ->whereColumn("{$target}.id", "{$foreignKey->table}.{$foreignKey->column}");
                })
                ->exists();

            if ($missing) {
                throw new RuntimeException(
                    "Mail Notifications adoption cannot restore [{$foreignKey->name}] because host references are unreconciled.",
                );
            }

            $schema->table($foreignKey->table, static function (Blueprint $table) use (
                $foreignKey,
                $target,
            ): void {
                $definition = $table->foreign($foreignKey->column, $foreignKey->name)
                    ->references('id')
                    ->on($target);

                match ($foreignKey->onDelete) {
                    'cascade' => $definition->cascadeOnDelete(),
                    'null' => $definition->nullOnDelete(),
                    default => $definition->restrictOnDelete(),
                };
            });
        }
    }

    private function dropSources(MailNotificationsAdoptionPlan $plan): void
    {
        $schema = Schema::connection($plan->connection);

        foreach ($this->sourceTables($plan) as $table) {
            $schema->drop($table);
        }
    }

    /**
     * @return list<string>
     */
    private function sourceTables(MailNotificationsAdoptionPlan $plan): array
    {
        return array_values(array_unique(array_filter([
            $plan->notifications?->table,
            $plan->scheduledMessages?->table,
        ], 'is_string')));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertChunks(Connection $connection, string $table, array $rows): void
    {
        foreach (array_chunk($rows, 250) as $chunk) {
            $connection->table($table)->insert($chunk);
        }
    }

    /**
     * @param  array<string, string>  $columns
     */
    private function value(stdClass $row, array $columns, string $canonical): mixed
    {
        $source = $columns[$canonical] ?? null;

        return $source !== null ? ($row->{$source} ?? null) : null;
    }

    /**
     * @param  array<string, string>  $columns
     * @param  array<string, string|null>  $aliases
     * @return array{string|null, string|null}
     */
    private function notifiable(
        stdClass $row,
        array $columns,
        array $aliases,
        string $label,
    ): array {
        $legacy = $this->nullableString($this->value($row, $columns, 'notifiable_type'));

        if ($legacy === null) {
            return [null, null];
        }

        if (! array_key_exists($legacy, $aliases)) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption {$label} notifiable type [{$legacy}] has no explicit mapping.",
            );
        }

        $alias = $aliases[$legacy];

        if ($alias === null) {
            return [null, null];
        }

        if ($this->notifiableTypes->resolve($alias) === null) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption notifiable alias [{$alias}] is not registered.",
            );
        }

        $id = $this->boundedNullableString(
            $this->value($row, $columns, 'notifiable_id'),
            128,
        );

        if ($id === null) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption {$label} has a notifiable type without an identity.",
            );
        }

        return [$alias, $id];
    }

    /**
     * @param  list<string>  $allowlist
     */
    private function safeMetadata(mixed $value, array $allowlist): string
    {
        $metadata = $value === null
            ? []
            : $this->decodedObject($value, 'metadata');
        $safe = Arr::only($metadata, $allowlist);
        $safe['imported_from'] = 'mail_notifications_adoption_v1';

        return json_encode($safe, JSON_THROW_ON_ERROR);
    }

    private function encodedList(mixed $value, bool $required = false): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                return '[]';
            }

            return null;
        }

        if (! is_array($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Mail Notifications adoption recipients must be a JSON list.');
        }

        $decoded = is_array($value)
            ? $value
            : json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException('Mail Notifications adoption recipients must be a JSON list.');
        }

        return $decoded === [] && ! $required
            ? null
            : json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedObject(mixed $value, string $label): array
    {
        if (! is_array($value) && ! is_string($value)) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption {$label} must be a JSON object.",
            );
        }

        $decoded = is_array($value)
            ? $value
            : json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption {$label} must be a JSON object.",
            );
        }

        $object = [];

        foreach ($decoded as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    "Mail Notifications adoption {$label} must use string keys.",
                );
            }

            $object[$key] = $item;
        }

        return $object;
    }

    private function requiredString(mixed $value, string $label): string
    {
        $normalized = $this->nullableString($value);

        if ($normalized === null) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption {$label} must be a non-empty string.",
            );
        }

        return $normalized;
    }

    private function boundedRequiredString(mixed $value, int $maximum, string $label): string
    {
        $normalized = $this->requiredString($value, $label);

        if (mb_strlen($normalized) > $maximum) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption {$label} exceeds {$maximum} characters.",
            );
        }

        return $normalized;
    }

    private function boundedNullableString(mixed $value, int $maximum): ?string
    {
        $normalized = $this->nullableString($value);

        if ($normalized !== null && mb_strlen($normalized) > $maximum) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption value exceeds {$maximum} characters.",
            );
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function uuidOrNull(mixed $value, string $label): ?string
    {
        $uuid = $this->nullableString($value);

        if ($uuid !== null && ! Str::isUuid($uuid)) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption {$label} must be a UUID or null.",
            );
        }

        return $uuid;
    }

    private function nonNegativeInteger(mixed $value, int $default, string $label): int
    {
        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException("Mail Notifications adoption {$label} is invalid.");
    }

    private function positiveInteger(mixed $value, int $default, string $label): int
    {
        $integer = $this->nonNegativeInteger($value, $default, $label);

        if ($integer < 1) {
            throw new InvalidArgumentException("Mail Notifications adoption {$label} must be positive.");
        }

        return $integer;
    }

    private function configuredMaximumAttempts(): int
    {
        $maximum = config('mail-notifications.scheduling.max_attempts', 3);

        if (! is_int($maximum) || $maximum < 1) {
            throw new InvalidArgumentException(
                'mail-notifications.scheduling.max_attempts must be a positive integer.',
            );
        }

        return $maximum;
    }

    private function assertHostForeignKeyTable(
        Builder $schema,
        LegacyMailForeignKey $foreignKey,
    ): void {
        if (! $schema->hasTable($foreignKey->table)
            || ! $schema->hasColumn($foreignKey->table, $foreignKey->column)) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption host foreign key [{$foreignKey->name}] has no valid table and column.",
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $foreignKeys
     */
    private function hasForeignKey(
        array $foreignKeys,
        LegacyMailForeignKey $expected,
    ): bool {
        return collect($foreignKeys)->contains(
            static fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === $expected->name
                || ($foreignKey['columns'] ?? null) === [$expected->column],
        );
    }
}
