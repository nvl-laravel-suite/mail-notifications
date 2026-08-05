<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * Enables the host-provided sensitive-storage transformer before package registration.
 */
final class SensitiveStorageHostServiceProvider extends ServiceProvider
{
    /**
     * Register the fixture host's sensitive-storage configuration.
     */
    public function register(): void
    {
        $this->app->make('config')->set(
            'mail-notifications.services.sensitive_storage_transformer',
            RotatingSensitiveDataTransformer::class,
        );
        $this->app->make('config')->set(
            'mail-notifications.privacy.sensitive_storage',
            [
                'enabled' => true,
                'max_transformed_bytes' => 262_144,
            ],
        );
        $this->app->make('config')->set(
            'mail-notifications-tests.sensitive_storage',
            [
                'current_key' => 'key-v1',
                'previous_keys' => [],
            ],
        );
    }
}
