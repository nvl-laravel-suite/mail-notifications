<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Exceptions\MailRetentionException;

/**
 * Validates and exposes bounded database-retention settings.
 */
final readonly class MailRetentionConfiguration
{
    public const int MAXIMUM_BATCH_SIZE = 1_000;

    public const int MAXIMUM_LIMIT = 10_000;

    private const int MAXIMUM_RETENTION_DAYS = 36_500;

    /**
     * Create the retention configuration reader.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Resolve the age of tracked notifications eligible for pruning.
     */
    public function notificationRetentionDays(): int
    {
        return $this->integer(
            key: 'mail-notifications.retention.notifications.days',
            default: 365,
            minimum: 1,
            maximum: self::MAXIMUM_RETENTION_DAYS,
            label: 'notification retention days',
        );
    }

    /**
     * Resolve the age of terminal scheduled messages eligible for pruning.
     */
    public function scheduledMessageRetentionDays(): int
    {
        return $this->integer(
            key: 'mail-notifications.retention.scheduled_messages.days',
            default: 90,
            minimum: 1,
            maximum: self::MAXIMUM_RETENTION_DAYS,
            label: 'scheduled-message retention days',
        );
    }

    /**
     * Determine whether terminal scheduled-mail rows participate in retention.
     */
    public function scheduledMessagePruningEnabled(): bool
    {
        $enabled = $this->config->get(
            'mail-notifications.retention.scheduled_messages.enabled',
            false,
        );

        if (! is_bool($enabled)) {
            throw new MailRetentionException(
                'Scheduled-mail retention enabled must be a boolean.',
            );
        }

        return $enabled;
    }

    /**
     * Resolve the tracked lifecycle statuses eligible for pruning.
     *
     * @return list<MailDeliveryStatus>
     */
    public function notificationStatuses(): array
    {
        $configured = $this->config->get(
            'mail-notifications.retention.notifications.statuses',
            [
                'delivered',
                'opened',
                'clicked',
                'bounced',
                'complained',
                'rejected',
                'failed',
                'unsubscribed',
            ],
        );

        if (! is_array($configured) || $configured === []) {
            throw new MailRetentionException(
                'Mail notification retention statuses must be a non-empty array.',
            );
        }

        $statuses = [];

        foreach ($configured as $value) {
            $status = is_string($value)
                ? MailDeliveryStatus::tryFrom($value)
                : null;

            if (! $status instanceof MailDeliveryStatus) {
                throw new MailRetentionException(
                    'Mail notification retention statuses must contain only valid delivery status strings.',
                );
            }

            $statuses[$status->value] = $status;
        }

        return array_values($statuses);
    }

    /**
     * Resolve terminal scheduled-mail statuses eligible for pruning.
     *
     * @return list<ScheduledMailStatus>
     */
    public function scheduledMessageStatuses(): array
    {
        $configured = $this->config->get(
            'mail-notifications.retention.scheduled_messages.statuses',
            ['sent', 'failed', 'cancelled'],
        );

        if (! is_array($configured) || $configured === []) {
            throw new MailRetentionException(
                'Scheduled-mail retention statuses must be a non-empty array.',
            );
        }

        $statuses = [];

        foreach ($configured as $value) {
            $status = is_string($value)
                ? ScheduledMailStatus::tryFrom($value)
                : null;

            if (! $status instanceof ScheduledMailStatus
                || ! $status->isTerminal()) {
                throw new MailRetentionException(
                    'Scheduled-mail retention statuses may contain only sent, failed, or cancelled; pending and processing are always protected.',
                );
            }

            $statuses[$status->value] = $status;
        }

        return array_values($statuses);
    }

    /**
     * Resolve the maximum IDs used in one count or delete query.
     *
     * @return int<1, 1000>
     */
    public function batchSize(): int
    {
        $value = $this->config->get(
            'mail-notifications.retention.batch_size',
            500,
        );

        if (! is_int($value)
            || $value < 1
            || $value > self::MAXIMUM_BATCH_SIZE) {
            throw $this->invalidInteger(
                label: 'batch size',
                minimum: 1,
                maximum: self::MAXIMUM_BATCH_SIZE,
            );
        }

        return $value;
    }

    /**
     * Resolve the maximum parent rows selected from each retained data set.
     *
     * @return int<1, 10000>
     */
    public function limit(?int $override = null): int
    {
        $value = $override ?? $this->config->get(
            'mail-notifications.retention.limit',
            5_000,
        );

        if (! is_int($value)
            || $value < 1
            || $value > self::MAXIMUM_LIMIT) {
            throw $this->invalidInteger(
                label: 'limit',
                minimum: 1,
                maximum: self::MAXIMUM_LIMIT,
            );
        }

        return $value;
    }

    /**
     * Read and validate one bounded integer setting.
     */
    private function integer(
        string $key,
        int $default,
        int $minimum,
        int $maximum,
        string $label,
    ): int {
        return $this->validateInteger(
            value: $this->config->get($key, $default),
            minimum: $minimum,
            maximum: $maximum,
            label: $label,
        );
    }

    /**
     * Validate one bounded integer value.
     */
    private function validateInteger(
        mixed $value,
        int $minimum,
        int $maximum,
        string $label,
    ): int {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw $this->invalidInteger($label, $minimum, $maximum);
        }

        return $value;
    }

    /**
     * Build one stable bounded-integer configuration exception.
     */
    private function invalidInteger(
        string $label,
        int $minimum,
        int $maximum,
    ): MailRetentionException {
        return new MailRetentionException(sprintf(
            'Mail notification retention %s must be an integer between %d and %d.',
            $label,
            $minimum,
            $maximum,
        ));
    }
}
