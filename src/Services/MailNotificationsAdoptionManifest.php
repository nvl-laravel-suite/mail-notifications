<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use InvalidArgumentException;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\ValueObjects\LegacyMailForeignKey;
use Nvl\MailNotifications\ValueObjects\LegacyMailNotificationMapping;
use Nvl\MailNotifications\ValueObjects\LegacyMailTableStage;
use Nvl\MailNotifications\ValueObjects\LegacyScheduledMailMapping;
use Nvl\MailNotifications\ValueObjects\MailNotificationsAdoptionPlan;

/**
 * Validates a complete legacy-to-package adoption manifest.
 */
final class MailNotificationsAdoptionManifest
{
    /** @var list<string> */
    private const array NOTIFICATION_REQUIRED_COLUMNS = [
        'id',
        'status',
        'mailer',
        'message_category',
        'to_recipients',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const array NOTIFICATION_OPTIONAL_COLUMNS = [
        'correlation_id',
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
    ];

    /** @var list<string> */
    private const array SCHEDULED_REQUIRED_COLUMNS = [
        'id',
        'factory_key',
        'payload',
        'to_recipients',
        'status',
        'scheduled_for',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const array SCHEDULED_OPTIONAL_COLUMNS = [
        'available_at',
        'cc_recipients',
        'bcc_recipients',
        'attempts',
        'max_attempts',
        'last_attempt_at',
        'last_error_code',
        'notifiable_type',
        'notifiable_id',
        'metadata',
        'sent_at',
        'failed_at',
        'cancelled_at',
        'redacted_at',
    ];

    /**
     * @param  array<array-key, mixed>  $manifest
     */
    public function normalize(array $manifest): MailNotificationsAdoptionPlan
    {
        $this->rejectUnknown($manifest, [
            'version',
            'connection',
            'staging',
            'notifications',
            'scheduled_messages',
            'foreign_keys',
            'drop_sources',
        ], 'manifest');

        if (($manifest['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Mail Notifications adoption manifest version must be 1.');
        }

        $connection = $manifest['connection'] ?? config('mail-notifications.storage.connection');

        if ($connection !== null && (! is_string($connection) || trim($connection) === '')) {
            throw new InvalidArgumentException('Mail Notifications adoption connection must be a name or null.');
        }

        $stages = $this->stages($manifest['staging'] ?? []);
        $notifications = $this->notificationMapping($manifest['notifications'] ?? null);
        $scheduled = $this->scheduledMapping($manifest['scheduled_messages'] ?? null);

        if ($notifications === null && $scheduled === null) {
            throw new InvalidArgumentException(
                'Mail Notifications adoption requires notifications or scheduled_messages.',
            );
        }

        $maximum = config('mail-notifications.adoption.maximum_records', 10_000);

        if (! is_int($maximum) || $maximum < 1) {
            throw new InvalidArgumentException(
                'mail-notifications.adoption.maximum_records must be a positive integer.',
            );
        }

        $expectedNotifications = $notifications instanceof LegacyMailNotificationMapping
            ? $notifications->expectedCount
            : 0;
        $expectedScheduled = $scheduled instanceof LegacyScheduledMailMapping
            ? $scheduled->expectedCount
            : 0;
        $expected = $expectedNotifications + $expectedScheduled;

        if ($expected > $maximum) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption exceeds the configured {$maximum} record limit.",
            );
        }

        return new MailNotificationsAdoptionPlan(
            connection: is_string($connection) ? trim($connection) : null,
            stages: $stages,
            notifications: $notifications,
            scheduledMessages: $scheduled,
            foreignKeys: $this->foreignKeys($manifest['foreign_keys'] ?? []),
            dropSources: ($manifest['drop_sources'] ?? false) === true,
        );
    }

    /**
     * @return list<LegacyMailTableStage>
     */
    private function stages(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Mail Notifications staging must be a JSON list.');
        }

        $stages = [];
        $tables = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Every Mail Notifications staging entry must be an object.');
            }

            $this->rejectUnknown($entry, ['source_table', 'staging_table'], 'staging entry');
            $source = $this->identifier($entry['source_table'] ?? null, 'staging source_table');
            $target = $this->identifier($entry['staging_table'] ?? null, 'staging staging_table');

            if ($source === $target || isset($tables[$source]) || isset($tables[$target])) {
                throw new InvalidArgumentException('Mail Notifications staging table names must be distinct.');
            }

            $tables[$source] = true;
            $tables[$target] = true;
            $stages[] = new LegacyMailTableStage($source, $target);
        }

        return $stages;
    }

    private function notificationMapping(mixed $value): ?LegacyMailNotificationMapping
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Mail Notifications notifications mapping must be an object.');
        }

        $this->rejectUnknown($value, [
            'table',
            'expected_count',
            'columns',
            'status_map',
            'notifiable_type_map',
            'event_timestamps',
            'metadata_allowlist',
        ], 'notifications mapping');

        return new LegacyMailNotificationMapping(
            table: $this->identifier($value['table'] ?? null, 'notifications table'),
            expectedCount: $this->count($value['expected_count'] ?? null, 'notifications'),
            columns: $this->columns(
                $value['columns'] ?? null,
                self::NOTIFICATION_REQUIRED_COLUMNS,
                self::NOTIFICATION_OPTIONAL_COLUMNS,
                'notifications',
            ),
            statuses: $this->statusMap(
                $value['status_map'] ?? null,
                array_map(
                    static fn (MailDeliveryStatus $status): string => $status->value,
                    MailDeliveryStatus::cases(),
                ),
                'notifications',
            ),
            notifiableTypes: $this->nullableStringMap(
                $value['notifiable_type_map'] ?? [],
                'notifications notifiable_type_map',
            ),
            eventTimestamps: $this->eventTimestamps($value['event_timestamps'] ?? []),
            metadataAllowlist: $this->stringList(
                $value['metadata_allowlist'] ?? [],
                'notifications metadata_allowlist',
            ),
        );
    }

    private function scheduledMapping(mixed $value): ?LegacyScheduledMailMapping
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Mail Notifications scheduled_messages mapping must be an object.');
        }

        $this->rejectUnknown($value, [
            'table',
            'expected_count',
            'columns',
            'status_map',
            'notifiable_type_map',
            'factory_map',
            'metadata_allowlist',
        ], 'scheduled_messages mapping');

        return new LegacyScheduledMailMapping(
            table: $this->identifier($value['table'] ?? null, 'scheduled_messages table'),
            expectedCount: $this->count($value['expected_count'] ?? null, 'scheduled_messages'),
            columns: $this->columns(
                $value['columns'] ?? null,
                self::SCHEDULED_REQUIRED_COLUMNS,
                self::SCHEDULED_OPTIONAL_COLUMNS,
                'scheduled_messages',
            ),
            statuses: $this->statusMap(
                $value['status_map'] ?? null,
                array_map(
                    static fn (ScheduledMailStatus $status): string => $status->value,
                    ScheduledMailStatus::cases(),
                ),
                'scheduled_messages',
            ),
            notifiableTypes: $this->nullableStringMap(
                $value['notifiable_type_map'] ?? [],
                'scheduled_messages notifiable_type_map',
            ),
            factories: $this->factories($value['factory_map'] ?? null),
            metadataAllowlist: $this->stringList(
                $value['metadata_allowlist'] ?? [],
                'scheduled_messages metadata_allowlist',
            ),
        );
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $optional
     * @return array<string, string>
     */
    private function columns(mixed $value, array $required, array $optional, string $label): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("Mail Notifications {$label} columns must be an object.");
        }

        $allowed = array_merge($required, $optional);
        $columns = [];

        foreach ($value as $canonical => $source) {
            if (! is_string($canonical) || ! in_array($canonical, $allowed, true)) {
                throw new InvalidArgumentException(
                    "Mail Notifications {$label} contains an unknown canonical column.",
                );
            }

            $columns[$canonical] = $this->identifier(
                $source,
                "{$label} column {$canonical}",
            );
        }

        $missing = array_values(array_diff($required, array_keys($columns)));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                "Mail Notifications {$label} mapping is missing canonical column [{$missing[0]}].",
            );
        }

        return $columns;
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private function statusMap(mixed $value, array $allowed, string $label): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("Mail Notifications {$label} status_map must be a non-empty object.");
        }

        $statuses = [];

        foreach ($value as $legacy => $target) {
            if (! is_string($legacy)
                || trim($legacy) === ''
                || ! is_string($target)
                || ! in_array($target, $allowed, true)) {
                throw new InvalidArgumentException("Mail Notifications {$label} contains an invalid status mapping.");
            }

            $statuses[trim($legacy)] = $target;
        }

        return $statuses;
    }

    /**
     * @return array<string, string>
     */
    private function eventTimestamps(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Mail Notifications event_timestamps must be an object.');
        }

        $allowed = [
            MailDeliveryStatus::Delayed,
            MailDeliveryStatus::Delivered,
            MailDeliveryStatus::Opened,
            MailDeliveryStatus::Clicked,
            MailDeliveryStatus::Bounced,
            MailDeliveryStatus::Complained,
            MailDeliveryStatus::Rejected,
            MailDeliveryStatus::Failed,
            MailDeliveryStatus::Unsubscribed,
        ];
        $events = [];

        foreach ($value as $status => $column) {
            if (! is_string($status)
                || ! in_array(MailDeliveryStatus::tryFrom($status), $allowed, true)) {
                throw new InvalidArgumentException('Mail Notifications event_timestamps contains an invalid event type.');
            }

            $events[$status] = $this->identifier($column, "event timestamp {$status}");
        }

        return $events;
    }

    /**
     * @return array<string, array{alias: string, version: int}>
     */
    private function factories(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Mail Notifications factory_map must be an object.');
        }

        $factories = [];

        foreach ($value as $legacy => $factory) {
            if (! is_string($legacy) || trim($legacy) === '' || ! is_array($factory)) {
                throw new InvalidArgumentException('Mail Notifications factory_map contains an invalid entry.');
            }

            $this->rejectUnknown($factory, ['alias', 'version'], 'factory mapping');
            $alias = $factory['alias'] ?? null;
            $version = $factory['version'] ?? null;

            if (! is_string($alias)
                || trim($alias) === ''
                || mb_strlen(trim($alias)) > 128
                || ! is_int($version)
                || $version < 1) {
                throw new InvalidArgumentException('Mail Notifications factory_map aliases and versions are invalid.');
            }

            $factories[trim($legacy)] = [
                'alias' => trim($alias),
                'version' => $version,
            ];
        }

        return $factories;
    }

    /**
     * @return list<LegacyMailForeignKey>
     */
    private function foreignKeys(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Mail Notifications foreign_keys must be a JSON list.');
        }

        $keys = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Every Mail Notifications foreign key must be an object.');
            }

            $this->rejectUnknown($entry, ['table', 'column', 'name', 'on_delete'], 'foreign key');
            $onDelete = $entry['on_delete'] ?? null;

            if (! is_string($onDelete)
                || ! in_array($onDelete, ['cascade', 'null', 'restrict'], true)) {
                throw new InvalidArgumentException('Mail Notifications foreign key on_delete is invalid.');
            }

            $keys[] = new LegacyMailForeignKey(
                table: $this->identifier($entry['table'] ?? null, 'foreign key table'),
                column: $this->identifier($entry['column'] ?? null, 'foreign key column'),
                name: $this->identifier($entry['name'] ?? null, 'foreign key name'),
                onDelete: $onDelete,
            );
        }

        return $keys;
    }

    /**
     * @return array<string, string|null>
     */
    private function nullableStringMap(mixed $value, string $label): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("Mail Notifications {$label} must be an object.");
        }

        $map = [];

        foreach ($value as $source => $target) {
            if (! is_string($source)
                || trim($source) === ''
                || ($target !== null && (! is_string($target) || trim($target) === ''))) {
                throw new InvalidArgumentException("Mail Notifications {$label} contains an invalid entry.");
            }

            $map[trim($source)] = is_string($target) ? trim($target) : null;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Mail Notifications {$label} must be a JSON list.");
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '' || mb_strlen(trim($item)) > 128) {
                throw new InvalidArgumentException("Mail Notifications {$label} contains an invalid value.");
            }

            $items[] = trim($item);
        }

        return array_values(array_unique($items));
    }

    private function count(mixed $value, string $label): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException(
                "Mail Notifications {$label} expected_count must be a non-negative integer.",
            );
        }

        return $value;
    }

    private function identifier(mixed $value, string $label): string
    {
        if (! is_string($value)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                "Mail Notifications {$label} must be a safe SQL identifier.",
            );
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $allowed
     */
    private function rejectUnknown(array $value, array $allowed, string $label): void
    {
        $unknown = array_values(array_diff(array_keys($value), $allowed));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Mail Notifications {$label} contains unknown option [{$unknown[0]}].",
            );
        }
    }
}
