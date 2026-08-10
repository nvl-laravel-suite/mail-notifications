<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Markdown;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\MailNotifications\Console\Commands\AnonymizeMailNotificationsCommand;
use Nvl\MailNotifications\Console\Commands\MailNotificationsDoctorCommand;
use Nvl\MailNotifications\Console\Commands\ProcessScheduledMailCommand;
use Nvl\MailNotifications\Console\Commands\PruneMailNotificationsCommand;
use Nvl\MailNotifications\Console\Commands\RecoverScheduledMailCommand;
use Nvl\MailNotifications\Console\Commands\RemoveRemoteWebhooksCommand;
use Nvl\MailNotifications\Console\Commands\SyncRemoteWebhooksCommand;
use Nvl\MailNotifications\Contracts\ProviderAdapter;
use Nvl\MailNotifications\Contracts\ProviderMessageIdResolver;
use Nvl\MailNotifications\Contracts\ProvidesNotifiableTypes;
use Nvl\MailNotifications\Contracts\RemoteWebhookManager;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\Contracts\SensitiveDataRedactor;
use Nvl\MailNotifications\Contracts\SensitiveDataTransformer;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Laravel\Listeners\TrackMessageAfterSending;
use Nvl\MailNotifications\Laravel\Listeners\TrackMessageBeforeSending;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\Services\DefaultSensitiveDataRedactor;
use Nvl\MailNotifications\Services\MailAnonymizationConfiguration;
use Nvl\MailNotifications\Services\MailHistoryAnonymizer;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\MailRetentionConfiguration;
use Nvl\MailNotifications\Services\MailRetentionPruner;
use Nvl\MailNotifications\Services\MailTestingInterceptor;
use Nvl\MailNotifications\Services\MailTheme;
use Nvl\MailNotifications\Services\MailTrackingEventDispatcher;
use Nvl\MailNotifications\Services\ProviderMessageIdRegistry;
use Nvl\MailNotifications\Services\ProviderRegistry;
use Nvl\MailNotifications\Services\RemoteWebhookManagerRegistry;
use Nvl\MailNotifications\Services\ScheduledMailClaimer;
use Nvl\MailNotifications\Services\ScheduledMailConfiguration;
use Nvl\MailNotifications\Services\ScheduledMailFinalizer;
use Nvl\MailNotifications\Services\ScheduledMailInputGuard;
use Nvl\MailNotifications\Services\ScheduledMailProcessor;
use Nvl\MailNotifications\Services\ScheduledMailReadiness;
use Nvl\MailNotifications\Services\ScheduledMailRecovery;
use Nvl\MailNotifications\Services\ScheduledMailScheduler;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Services\SensitiveStorageCodec;
use Nvl\MailNotifications\Services\SensitiveStorageConfiguration;
use Nvl\MailNotifications\Services\SymfonyMessageIdResolver;
use Nvl\MailNotifications\Services\TrackingEligibility;
use Nvl\MailNotifications\Services\TrackingRuntime;
use Nvl\MailNotifications\Services\WebhookProcessor;
use Nvl\MailNotifications\Support\SensitiveStorageBridge;
use Nvl\MailNotifications\Support\TrackingRuntimeBridge;

/**
 * Registers provider-neutral tracking and a configurable Laravel mail presentation.
 */
final class MailNotificationsServiceProvider extends ServiceProvider
{
    /**
     * Register package configuration and tracking services.
     */
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(
            dirname(__DIR__, 2).'/config/mail-notifications.php',
            'mail-notifications',
        );
        $this->registerConfiguredExtensions(
            'mail-notifications.extensions.provider_adapters',
            ProviderAdapter::class,
            ProviderAdapter::CONTAINER_TAG,
        );
        $this->registerConfiguredExtensions(
            'mail-notifications.extensions.message_id_resolvers',
            ProviderMessageIdResolver::class,
            ProviderMessageIdResolver::TAG,
        );
        $this->registerConfiguredExtensions(
            'mail-notifications.extensions.notifiable_type_providers',
            ProvidesNotifiableTypes::class,
            ProvidesNotifiableTypes::TAG,
        );
        $this->registerConfiguredExtensions(
            'mail-notifications.extensions.scheduled_message_factories',
            ScheduledMessageFactory::class,
            ScheduledMessageFactory::TAG,
        );
        $this->registerConfiguredExtensions(
            'mail-notifications.extensions.webhook_managers',
            RemoteWebhookManager::class,
            RemoteWebhookManager::TAG,
        );

        $this->app->singleton(TrackingEligibility::class);
        $this->app->singleton(MailTheme::class);
        $this->app->singleton(MailTestingInterceptor::class);
        $this->app->singleton(SymfonyMessageIdResolver::class);
        $this->app->singleton(
            MailTrackingEventDispatcher::class,
            static function (Application $app): MailTrackingEventDispatcher {
                $database = $app->make('db');

                return new MailTrackingEventDispatcher(
                    events: static fn (): Dispatcher => $app->make(Dispatcher::class),
                    transactions: static fn (): DatabaseTransactionsManager => $app->make('db.transactions'),
                    exceptions: $app->make(ExceptionHandler::class),
                    database: $database,
                    config: $app->make(Repository::class),
                );
            },
        );
        $this->app->singleton(
            SensitiveDataRedactor::class,
            $this->configuredImplementation(
                'mail-notifications.services.sensitive_data_redactor',
                SensitiveDataRedactor::class,
                DefaultSensitiveDataRedactor::class,
            ),
        );
        $this->app->singleton(
            TrackingLifecycle::class,
            $this->configuredImplementation(
                'mail-notifications.services.tracking_lifecycle',
                TrackingLifecycle::class,
                DatabaseTrackingLifecycle::class,
            ),
        );
        $sensitiveStorage = new SensitiveStorageConfiguration(
            $this->app->make(Repository::class),
        );
        $transformerClass = $sensitiveStorage->transformerClass();
        $sensitiveStorage->maximumTransformedBytes();
        $this->app->instance(
            SensitiveStorageConfiguration::class,
            $sensitiveStorage,
        );

        if ($transformerClass !== null) {
            $this->app->singleton(
                SensitiveDataTransformer::class,
                $transformerClass,
            );
        }

        $this->app->singleton(
            SensitiveStorageCodec::class,
            static fn (Application $app): SensitiveStorageCodec => new SensitiveStorageCodec(
                configuration: $app->make(
                    SensitiveStorageConfiguration::class,
                ),
                transformer: $transformerClass !== null
                    ? $app->make(SensitiveDataTransformer::class)
                    : null,
            ),
        );
        $this->app->singleton(
            ProviderRegistry::class,
            static fn (Application $app): ProviderRegistry => new ProviderRegistry(
                adapters: $app->tagged(ProviderAdapter::CONTAINER_TAG),
            ),
        );
        $this->app->singleton(
            ProviderMessageIdRegistry::class,
            function (Application $app): ProviderMessageIdRegistry {
                return new ProviderMessageIdRegistry(
                    resolvers: $this->messageIdResolvers($app),
                    fallback: $app->make(SymfonyMessageIdResolver::class),
                );
            },
        );
        $this->app->singleton(
            RemoteWebhookManagerRegistry::class,
            static fn (Application $app): RemoteWebhookManagerRegistry => new RemoteWebhookManagerRegistry(
                managers: $app->tagged(RemoteWebhookManager::TAG),
            ),
        );
        $this->app->singleton(
            MailNotificationNotifiableTypeRegistry::class,
            function (Application $app): MailNotificationNotifiableTypeRegistry {
                $configuredTypes = config('mail-notifications.notifiable_types', []);

                if (! is_array($configuredTypes)) {
                    throw new LogicException(
                        'Configured mail notification notifiable types must be an array.',
                    );
                }

                return new MailNotificationNotifiableTypeRegistry(
                    providers: $app->tagged(ProvidesNotifiableTypes::TAG),
                    configuredTypes: $configuredTypes,
                );
            },
        );
        $this->app->singleton(ScheduledMailConfiguration::class);
        $this->app->singleton(
            ScheduledMessageFactoryRegistry::class,
            static fn (Application $app): ScheduledMessageFactoryRegistry => new ScheduledMessageFactoryRegistry(
                factories: $app->tagged(ScheduledMessageFactory::TAG),
            ),
        );
        $this->app->singleton(ScheduledMailScheduler::class);
        $this->app->singleton(ScheduledMailClaimer::class);
        $this->app->singleton(ScheduledMailFinalizer::class);
        $this->app->singleton(ScheduledMailInputGuard::class);
        $this->app->singleton(ScheduledMailProcessor::class);
        $this->app->singleton(ScheduledMailRecovery::class);
        $this->app->singleton(MailRetentionConfiguration::class);
        $this->app->singleton(
            ScheduledMailReadiness::class,
            static fn (Application $app): ScheduledMailReadiness => new ScheduledMailReadiness(
                configuration: $app->make(ScheduledMailConfiguration::class),
                retention: $app->make(MailRetentionConfiguration::class),
                anonymization: $app->make(
                    MailAnonymizationConfiguration::class,
                ),
                factories: static fn (): ScheduledMessageFactoryRegistry => $app->make(
                    ScheduledMessageFactoryRegistry::class,
                ),
            ),
        );
        $this->app->singleton(MailRetentionPruner::class);
        $this->app->singleton(MailAnonymizationConfiguration::class);
        $this->app->singleton(MailHistoryAnonymizer::class);
        $this->app->singleton(TrackingRuntime::class);
        $this->app->singleton(WebhookProcessor::class);
    }

    /**
     * Publish package resources and attach Laravel mail lifecycle listeners.
     */
    public function boot(): void
    {
        if ($this->app->bound(TypeScriptSourceRegistry::class)) {
            $this->app->make(TypeScriptSourceRegistry::class)->register(
                __DIR__.'/..',
                'nvl/mail-notifications',
            );
        }

        TrackingRuntimeBridge::clear();
        SensitiveStorageBridge::clear();
        SensitiveStorageBridge::use(
            $this->app->make(SensitiveStorageCodec::class),
        );

        if ($this->presentationEnabled()) {
            if ($this->presentationAutoLoadEnabled()) {
                $this->registerPresentationPath();
            }

            $theme = $this->app->make(MailTheme::class);
            $views = $this->app->make(ViewFactory::class);
            $views->share('nvlMailTheme', $theme->tokens());
            $views->share('nvlMailBrand', $theme->brand());
        }

        if ($this->packageEnabled()) {
            $this->app->make(MailTestingInterceptor::class)->apply();
            $this->bootTracking();
        }

        $this->publishes([
            dirname(__DIR__, 2).'/config/mail-notifications.php' => config_path('mail-notifications.php'),
        ], 'mail-notifications-config');
        $this->publishesMigrations([
            dirname(__DIR__, 2).'/database/migrations' => database_path('migrations'),
        ], 'mail-notifications-migrations');
        $this->publishes([
            dirname(__DIR__, 2).'/resources/boost/skills' => base_path('.agents/skills'),
        ], 'mail-notifications-skills');
        $this->publishes([
            dirname(__DIR__, 2).'/resources/views/mail' => resource_path('views/vendor/mail'),
        ], 'mail-notifications-mail-views');

        if ($this->configurationBoolean(
            'mail-notifications.migrations.enabled',
            true,
        )) {
            $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                AnonymizeMailNotificationsCommand::class,
                MailNotificationsDoctorCommand::class,
                PruneMailNotificationsCommand::class,
                ProcessScheduledMailCommand::class,
                RecoverScheduledMailCommand::class,
                RemoveRemoteWebhooksCommand::class,
                SyncRemoteWebhooksCommand::class,
            ]);
        }
    }

    /**
     * Add package mail components after host paths while respecting Laravel mail configuration.
     */
    private function registerPresentationPath(): void
    {
        $configuredPaths = config('mail.markdown.paths', []);
        $paths = is_array($configuredPaths)
            ? array_values(array_filter($configuredPaths, 'is_string'))
            : [];
        $paths[] = dirname(__DIR__, 2).'/resources/views/mail';

        $paths = array_values(array_unique($paths));

        config(['mail.markdown.paths' => $paths]);

        if ($this->app->resolved(Markdown::class)) {
            $this->app->make(Markdown::class)->loadComponentsFrom($paths);
        }
    }

    /**
     * Register tracking runtime and listeners only when tracking is enabled.
     */
    private function bootTracking(): void
    {
        $eligibility = $this->app->make(TrackingEligibility::class);

        if (! $eligibility->enabled()) {
            return;
        }

        $eligibility->failurePolicy();
        $eligibility->excludedMailers();
        $this->app->make(SymfonyMessageIdResolver::class)
            ->validateConfiguration();
        TrackingRuntimeBridge::use($this->app->make(TrackingRuntime::class));

        $events = $this->app->make(Dispatcher::class);
        $events->listen(MessageSending::class, TrackMessageBeforeSending::class);
        $events->listen(MessageSent::class, TrackMessageAfterSending::class);
    }

    /**
     * Determine whether any runtime package behavior should be enabled.
     */
    private function packageEnabled(): bool
    {
        return $this->configurationBoolean(
            'mail-notifications.enabled',
            true,
        );
    }

    /**
     * Determine whether package presentation configuration should be available.
     */
    private function presentationEnabled(): bool
    {
        return $this->packageEnabled()
            && $this->configurationBoolean(
                'mail-notifications.presentation.enabled',
                true,
            );
    }

    /**
     * Determine whether package presentation components should load automatically.
     */
    private function presentationAutoLoadEnabled(): bool
    {
        return $this->configurationBoolean(
            'mail-notifications.presentation.auto_load',
            true,
        );
    }

    /**
     * Read one package switch without unsafe truthy-value coercion.
     */
    private function configurationBoolean(string $key, bool $default): bool
    {
        $value = config($key, $default);

        if (! is_bool($value)) {
            throw new LogicException(
                "Mail notification configuration [{$key}] must be an actual boolean.",
            );
        }

        return $value;
    }

    /**
     * Register configured extension classes under their public container tag.
     *
     * @param  class-string  $contract
     */
    private function registerConfiguredExtensions(
        string $configKey,
        string $contract,
        string $tag,
    ): void {
        $configured = $this->app->make('config')->get($configKey, []);

        if (! is_array($configured)) {
            throw new LogicException(
                "Configured mail notification extensions [{$configKey}] must be an array.",
            );
        }

        $classes = [];

        foreach ($configured as $class) {
            if (! is_string($class) || ! is_a($class, $contract, true)) {
                throw new LogicException(
                    "Configured mail notification extension [{$configKey}] must implement [{$contract}].",
                );
            }

            if (! $this->app->bound($class)) {
                $this->app->singleton($class);
            }

            $classes[] = $class;
        }

        if ($classes !== []) {
            $this->app->tag(array_values(array_unique($classes)), $tag);
        }
    }

    /**
     * Resolve and validate one configurable package service implementation.
     *
     * @param  class-string  $contract
     * @param  class-string  $fallback
     * @return class-string
     */
    private function configuredImplementation(
        string $configKey,
        string $contract,
        string $fallback,
    ): string {
        $configured = $this->app->make('config')->get($configKey, $fallback);

        if (! is_string($configured) || ! is_a($configured, $contract, true)) {
            throw new LogicException(
                "Configured mail notification service [{$configKey}] must implement [{$contract}].",
            );
        }

        return $configured;
    }

    /**
     * Return provider-adapter and standalone message identifier resolvers in priority order.
     *
     * @return list<ProviderMessageIdResolver>
     */
    private function messageIdResolvers(Application $app): array
    {
        $resolvers = array_values(array_filter(
            $app->make(ProviderRegistry::class)->all(),
            static fn (ProviderAdapter $adapter): bool => $adapter instanceof ProviderMessageIdResolver,
        ));

        foreach ($app->tagged(ProviderMessageIdResolver::TAG) as $resolver) {
            if (! $resolver instanceof ProviderMessageIdResolver) {
                throw new LogicException(
                    'Tagged mail message identifier resolvers must implement ProviderMessageIdResolver.',
                );
            }

            $resolvers[] = $resolver;
        }

        return $resolvers;
    }
}
