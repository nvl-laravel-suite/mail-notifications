<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Exceptions\MailRetentionException;

/**
 * Validates the explicit bounded history-anonymization retention stage.
 */
final readonly class MailAnonymizationConfiguration
{
    public const int MAXIMUM_BATCH_SIZE = 1_000;

    public const int MAXIMUM_LIMIT = 10_000;

    private const int MAXIMUM_RETENTION_DAYS = 36_500;

    /**
     * Create the anonymization configuration reader.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Determine whether anonymization may mutate retained history.
     */
    public function enabled(): bool
    {
        return $this->boolean(
            key: 'mail-notifications.retention.anonymization.enabled',
            default: false,
            label: 'enabled',
        );
    }

    /**
     * Resolve the age of tracked notifications eligible for anonymization.
     */
    public function notificationRetentionDays(): int
    {
        return $this->integer(
            key: 'mail-notifications.retention.anonymization.notifications.days',
            default: 180,
            minimum: 1,
            maximum: self::MAXIMUM_RETENTION_DAYS,
            label: 'notification days',
        );
    }

    /**
     * Resolve tracked lifecycle statuses eligible for anonymization.
     *
     * @return list<MailDeliveryStatus>
     */
    public function notificationStatuses(): array
    {
        $configured = $this->config->get(
            'mail-notifications.retention.anonymization.notifications.statuses',
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
                'Mail notification anonymization statuses must be a non-empty array.',
            );
        }

        $statuses = [];

        foreach ($configured as $value) {
            $status = is_string($value)
                ? MailDeliveryStatus::tryFrom($value)
                : null;

            if (! $status instanceof MailDeliveryStatus) {
                throw new MailRetentionException(
                    'Mail notification anonymization statuses must contain only valid delivery status strings.',
                );
            }

            $statuses[$status->value] = $status;
        }

        return array_values($statuses);
    }

    /**
     * Determine whether terminal scheduled messages may be anonymized.
     */
    public function scheduledMessageAnonymizationEnabled(): bool
    {
        return $this->boolean(
            key: 'mail-notifications.retention.anonymization.scheduled_messages.enabled',
            default: false,
            label: 'scheduled-message enabled',
        );
    }

    /**
     * Resolve the age of terminal scheduled messages eligible for anonymization.
     */
    public function scheduledMessageRetentionDays(): int
    {
        return $this->integer(
            key: 'mail-notifications.retention.anonymization.scheduled_messages.days',
            default: 90,
            minimum: 1,
            maximum: self::MAXIMUM_RETENTION_DAYS,
            label: 'scheduled-message days',
        );
    }

    /**
     * Resolve terminal scheduled statuses eligible for anonymization.
     *
     * @return list<ScheduledMailStatus>
     */
    public function scheduledMessageStatuses(): array
    {
        $configured = $this->config->get(
            'mail-notifications.retention.anonymization.scheduled_messages.statuses',
            ['sent', 'failed', 'cancelled'],
        );

        if (! is_array($configured) || $configured === []) {
            throw new MailRetentionException(
                'Scheduled-mail anonymization statuses must be a non-empty array.',
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
                    'Scheduled-mail anonymization statuses may contain only sent, failed, or cancelled.',
                );
            }

            $statuses[$status->value] = $status;
        }

        return array_values($statuses);
    }

    /**
     * Resolve the maximum rows updated in one storage batch.
     *
     * @return int<1, 1000>
     */
    public function batchSize(): int
    {
        $value = $this->config->get(
            'mail-notifications.retention.anonymization.batch_size',
            500,
        );

        if (! is_int($value)
            || $value < 1
            || $value > self::MAXIMUM_BATCH_SIZE) {
            throw new MailRetentionException(sprintf(
                'Mail notification anonymization batch size must be an integer between 1 and %d.',
                self::MAXIMUM_BATCH_SIZE,
            ));
        }

        return $value;
    }

    /**
     * Resolve the independent maximum selected from each anonymized data set.
     */
    public function limit(?int $override = null): int
    {
        if ($override !== null) {
            return $this->validateInteger(
                value: $override,
                minimum: 1,
                maximum: self::MAXIMUM_LIMIT,
                label: 'limit',
            );
        }

        return $this->integer(
            key: 'mail-notifications.retention.anonymization.limit',
            default: 5_000,
            minimum: 1,
            maximum: self::MAXIMUM_LIMIT,
            label: 'limit',
        );
    }

    /**
     * Read and validate one strict boolean setting.
     */
    private function boolean(
        string $key,
        bool $default,
        string $label,
    ): bool {
        $value = $this->config->get($key, $default);

        if (! is_bool($value)) {
            throw new MailRetentionException(sprintf(
                'Mail notification anonymization %s must be a boolean.',
                $label,
            ));
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
            throw new MailRetentionException(sprintf(
                'Mail notification anonymization %s must be an integer between %d and %d.',
                $label,
                $minimum,
                $maximum,
            ));
        }

        return $value;
    }
}
