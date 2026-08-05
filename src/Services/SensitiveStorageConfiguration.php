<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Contracts\SensitiveDataTransformer;
use Nvl\MailNotifications\Exceptions\SensitiveStorageException;

/**
 * Validates the opt-in host-owned sensitive-storage protection settings.
 */
final readonly class SensitiveStorageConfiguration
{
    public const int MAXIMUM_TRANSFORMED_BYTES = 10_485_760;

    /**
     * Create the sensitive-storage configuration reader.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Determine whether new sensitive arrays must be protected for storage.
     */
    public function enabled(): bool
    {
        $enabled = $this->config->get(
            'mail-notifications.privacy.sensitive_storage.enabled',
            false,
        );

        if (! is_bool($enabled)) {
            throw new SensitiveStorageException(
                'Mail notification sensitive storage enabled must be a boolean.',
            );
        }

        return $enabled;
    }

    /**
     * Resolve the configured host transformer class when one is supplied.
     *
     * @return class-string<SensitiveDataTransformer>|null
     */
    public function transformerClass(): ?string
    {
        $enabled = $this->enabled();
        $configured = $this->config->get(
            'mail-notifications.services.sensitive_storage_transformer',
        );

        if ($configured === null) {
            if ($enabled) {
                throw new SensitiveStorageException(
                    'Enabled mail notification sensitive storage requires a transformer class.',
                );
            }

            return null;
        }

        if (! is_string($configured)
            || ! is_a($configured, SensitiveDataTransformer::class, true)) {
            throw new SensitiveStorageException(sprintf(
                'Configured mail notification service [mail-notifications.services.sensitive_storage_transformer] must implement [%s].',
                SensitiveDataTransformer::class,
            ));
        }

        return $configured;
    }

    /**
     * Resolve the maximum opaque transformer payload accepted per array.
     *
     * @return int<1, 10485760>
     */
    public function maximumTransformedBytes(): int
    {
        $maximum = $this->config->get(
            'mail-notifications.privacy.sensitive_storage.max_transformed_bytes',
            262_144,
        );

        if (! is_int($maximum)
            || $maximum < 1
            || $maximum > self::MAXIMUM_TRANSFORMED_BYTES) {
            throw new SensitiveStorageException(sprintf(
                'Mail notification sensitive storage transformed byte limit must be an integer between 1 and %d.',
                self::MAXIMUM_TRANSFORMED_BYTES,
            ));
        }

        return $maximum;
    }
}
