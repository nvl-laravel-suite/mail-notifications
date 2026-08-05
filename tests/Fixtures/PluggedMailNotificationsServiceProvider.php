<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * Supplies host configuration before the package provider registers its extensions.
 */
final class PluggedMailNotificationsServiceProvider extends ServiceProvider
{
    /**
     * Register the fixture host's package configuration.
     */
    public function register(): void
    {
        $this->app->make('config')->set('mail-notifications', [
            'extensions' => [
                'provider_adapters' => [
                    PluggedProviderAdapter::class,
                ],
                'message_id_resolvers' => [
                    PluggedMessageIdResolver::class,
                ],
                'notifiable_type_providers' => [
                    PluggedNotifiableTypeProvider::class,
                ],
            ],
            'notifiable_types' => [
                'configured-trackable' => TestTrackable::class,
            ],
            'services' => [
                'tracking_lifecycle' => PluggedTrackingLifecycle::class,
                'sensitive_data_redactor' => PluggedSensitiveDataRedactor::class,
            ],
            'webhooks' => [
                'max_payload_bytes' => 4_096,
            ],
        ]);
    }
}
