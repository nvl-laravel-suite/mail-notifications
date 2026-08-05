<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Exceptions\ScheduledMailException;

/**
 * Validates and exposes bounded scheduled-mail runtime settings.
 */
final readonly class ScheduledMailConfiguration
{
    /**
     * Create the scheduling configuration reader.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Determine whether scheduled-mail runtime behavior is enabled.
     */
    public function enabled(): bool
    {
        $packageEnabled = $this->boolean(
            'mail-notifications.enabled',
            true,
        );
        $schedulingEnabled = $this->boolean(
            'mail-notifications.scheduling.enabled',
            false,
        );

        return $packageEnabled && $schedulingEnabled;
    }

    /**
     * Resolve the bounded number of messages handled in one command run.
     */
    public function batchSize(?int $override = null): int
    {
        if ($override !== null) {
            return $this->validateInteger(
                value: $override,
                minimum: 1,
                maximum: 1_000,
                label: 'batch size',
            );
        }

        return $this->integer(
            key: 'mail-notifications.scheduling.batch_size',
            default: 50,
            minimum: 1,
            maximum: 1_000,
            label: 'batch size',
        );
    }

    /**
     * Resolve the bounded claim lease duration in seconds.
     */
    public function claimTtlSeconds(): int
    {
        return $this->integer(
            key: 'mail-notifications.scheduling.claim_ttl_seconds',
            default: 300,
            minimum: 1,
            maximum: 86_400,
            label: 'claim TTL',
        );
    }

    /**
     * Resolve the default attempt ceiling for new messages.
     */
    public function defaultMaxAttempts(): int
    {
        return $this->integer(
            key: 'mail-notifications.scheduling.max_attempts',
            default: 3,
            minimum: 1,
            maximum: 100,
            label: 'max attempts',
        );
    }

    /**
     * Resolve the maximum serialized payload size.
     */
    public function maximumPayloadBytes(): int
    {
        return $this->integer(
            key: 'mail-notifications.scheduling.max_payload_bytes',
            default: 65_536,
            minimum: 1,
            maximum: 10_485_760,
            label: 'payload byte limit',
        );
    }

    /**
     * Resolve the maximum deduplicated TO, CC, and BCC recipient count.
     */
    public function maximumRecipients(): int
    {
        return $this->integer(
            key: 'mail-notifications.scheduling.max_recipients',
            default: 1_000,
            minimum: 1,
            maximum: 10_000,
            label: 'recipient limit',
        );
    }

    /**
     * Resolve deterministic retry delay for the completed attempt number.
     */
    public function retryDelaySeconds(int $attempt): int
    {
        if ($attempt < 1) {
            throw new ScheduledMailException(
                'Scheduled mail retry attempts must be positive integers.',
            );
        }

        $configured = $this->config->get(
            'mail-notifications.scheduling.backoff_seconds',
            [60, 300, 900],
        );

        if (! is_array($configured) || $configured === []) {
            throw new ScheduledMailException(
                'Scheduled mail backoff must be a non-empty array.',
            );
        }

        $delays = [];

        foreach ($configured as $delay) {
            if (! is_int($delay) || $delay < 0 || $delay > 604_800) {
                throw new ScheduledMailException(
                    'Scheduled mail backoff values must be integers between 0 and 604800.',
                );
            }

            $delays[] = $delay;
        }

        return $delays[min($attempt - 1, count($delays) - 1)];
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
     * Read one scheduling switch without unsafe truthy-value coercion.
     */
    private function boolean(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);

        if (! is_bool($value)) {
            throw new ScheduledMailException(
                "Scheduled mail configuration [{$key}] must be a boolean.",
            );
        }

        return $value;
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
            throw new ScheduledMailException(sprintf(
                'Scheduled mail %s must be an integer between %d and %d.',
                $label,
                $minimum,
                $maximum,
            ));
        }

        return $value;
    }
}
