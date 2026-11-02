<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Nvl\MailNotifications\Console\Commands\AdoptMailNotificationsCommand;
use Nvl\MailNotifications\Console\Commands\AnonymizeMailNotificationsCommand;
use Nvl\MailNotifications\Console\Commands\DispatchPendingMail;
use Nvl\MailNotifications\Console\Commands\MailNotificationsDoctorCommand;
use Nvl\MailNotifications\Console\Commands\ProcessScheduledMailCommand;
use Nvl\MailNotifications\Console\Commands\PruneMailHistory;
use Nvl\MailNotifications\Console\Commands\PruneMailNotificationsCommand;
use Nvl\MailNotifications\Console\Commands\PruneMessageAttachments;
use Nvl\MailNotifications\Console\Commands\PruneOrphanMailFiles;
use Nvl\MailNotifications\Console\Commands\QuarantineRestoredMail;
use Nvl\MailNotifications\Console\Commands\ReconcileMailWebhooks;
use Nvl\MailNotifications\Console\Commands\RecoverClientWebhooks;
use Nvl\MailNotifications\Console\Commands\RecoverProviderSyncs;
use Nvl\MailNotifications\Console\Commands\RecoverScheduledMailCommand;
use Nvl\MailNotifications\Console\Commands\RemoveRemoteWebhooksCommand;
use Nvl\MailNotifications\Console\Commands\SyncMailIntegrations;
use Nvl\MailNotifications\Console\Commands\SyncRemoteWebhooksCommand;
use Nvl\MailNotifications\Contracts\DeliveryProfileResolver;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Contracts\MailOwnerWorklist;
use Nvl\MailNotifications\Contracts\ProviderAdapter;
use Nvl\MailNotifications\Contracts\ProviderMessageIdResolver;
use Nvl\MailNotifications\Contracts\ProvidesNotifiableTypes;
use Nvl\MailNotifications\Contracts\RemoteWebhookManager;
use Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization;
use Nvl\MailNotifications\Contracts\ScheduledMailSchedulerContract;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\Contracts\SensitiveDataRedactor;
use Nvl\MailNotifications\Contracts\SensitiveDataTransformer;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Laravel\Listeners\TrackMessageAfterSending;
use Nvl\MailNotifications\Laravel\Listeners\TrackMessageBeforeSending;
use Nvl\MailNotifications\Ownership\MailAdoptionAdapter;
use Nvl\MailNotifications\Ownership\MailResourceRegistrar;
use Nvl\MailNotifications\Ownership\MailTrackingContextParticipant;
use Nvl\MailNotifications\Ownership\StoredMailOwnerWorklist;
use Nvl\MailNotifications\Services\ConfiguredDeliveryProfileResolver;
use Nvl\MailNotifications\Services\ConfiguredMailNotificationReadAuthorization;
use Nvl\MailNotifications\Services\ConfiguredScheduledMailReadAuthorization;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\Services\DefaultSensitiveDataRedactor;
use Nvl\MailNotifications\Services\MailAnonymizationConfiguration;
use Nvl\MailNotifications\Services\MailHistoryAnonymizer;
use Nvl\MailNotifications\Services\MailModelRegistry;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\Services\MailRetentionConfiguration;
use Nvl\MailNotifications\Services\MailRetentionPruner;
use Nvl\MailNotifications\Services\MailTestingInterceptor;
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
use Nvl\MailNotifications\Services\SettingsDeliveryProfileResolver;
use Nvl\MailNotifications\Services\SymfonyMessageIdResolver;
use Nvl\MailNotifications\Services\TrackingEligibility;
use Nvl\MailNotifications\Services\TrackingRuntime;
use Nvl\MailNotifications\Services\WebhookProcessor;
use Nvl\MailNotifications\Support\SensitiveStorageBridge;
use Nvl\MailNotifications\Support\TrackingRuntimeBridge;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Integrations\OptionalIntegration;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipContextParticipants;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;

/**
 * Registers provider-neutral tracking and scheduled delivery.
 */
final class MailNotificationsServiceProvider extends ServiceProvider implements OwnershipIntegratedProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /**
     * Register package configuration and tracking services.
     */
    public function register(): void
    {
        foreach (\Nvl\MailNotifications\Support\legacyManagedNamespaces() as $previous => $canonical) {
            $this->app->alias($canonical, $previous);
        }

        $this->app->register(SupportServiceProvider::class);
        $this->app->bindIf(MailOwnerWorklist::class, StoredMailOwnerWorklist::class);
        PackageDoctorContributor::register($this->app, 'nvl/mail-notifications', fn (): array => $this->app->make(MailNotificationsDoctor::class)->inspect());

        $this->mergePackageConfiguration(
            dirname(__DIR__, 2).'/config/nvl-mail-notifications.php',
            'mail-notifications',
        );
        $this->app->booting(function (): void {
            if (config('nvl-mail-notifications.managed.enabled') === true) {
                $this->app->register(ManagedDeliveryServiceProvider::class);
            }
        });
        $this->app->singleton(MailModelRegistry::class);
        $this->app->booting(fn () => $this->app->make(MailModelRegistry::class)->prepare());
        $this->app->booted(function (): void {
            $this->app->make(MailResourceRegistrar::class)->register($this->app->make(OwnershipResourceRegistry::class),
                config('nvl-ownership.enabled') === true && config('nvl-mail-notifications.ownership.scoped', true) === true);
            $this->app->make(OwnershipContextParticipants::class)->register(MailTrackingContextParticipant::class);
            if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
                $this->app->make(OwnershipAdoptionRegistry::class)->register('mail-notifications', MailAdoptionAdapter::class);
            }
        });
        $this->app->bindIf(DeliveryProfileResolver::class, static function (Application $app): DeliveryProfileResolver {
            $requested = $app->make(Repository::class)->get('nvl-mail-notifications.scheduling.delivery_profile_setting') !== null;
            $enabled = $app->make(OptionalIntegration::class)->enabled(
                'nvl-mail-notifications.integrations.settings', SettingsServiceProvider::class, $requested,
            );

            return $app->make($enabled ? SettingsDeliveryProfileResolver::class : ConfiguredDeliveryProfileResolver::class);
        });
        $readAuthorization = config(
            'nvl-mail-notifications.management.authorization.class',
            ConfiguredMailNotificationReadAuthorization::class,
        );

        if (! is_string($readAuthorization)
            || ! is_a($readAuthorization, MailNotificationReadAuthorization::class, true)) {
            throw new LogicException(
                'nvl-mail-notifications.management.authorization.class must implement MailNotificationReadAuthorization.',
            );
        }

        $this->app->bindIf(
            MailNotificationReadAuthorization::class,
            $readAuthorization,
        );
        $scheduledReadAuthorization = config(
            'nvl-mail-notifications.management.scheduled_authorization.class',
            ConfiguredScheduledMailReadAuthorization::class,
        );

        if (! is_string($scheduledReadAuthorization)
            || ! is_a($scheduledReadAuthorization, ScheduledMailReadAuthorization::class, true)) {
            throw new LogicException(
                'nvl-mail-notifications.management.scheduled_authorization.class must implement ScheduledMailReadAuthorization.',
            );
        }

        $this->app->bindIf(
            ScheduledMailReadAuthorization::class,
            $scheduledReadAuthorization,
        );
        $this->registerConfiguredExtensions(
            'nvl-mail-notifications.extensions.provider_adapters',
            ProviderAdapter::class,
            ProviderAdapter::CONTAINER_TAG,
        );
        $this->registerConfiguredExtensions(
            'nvl-mail-notifications.extensions.message_id_resolvers',
            ProviderMessageIdResolver::class,
            ProviderMessageIdResolver::TAG,
        );
        $this->registerConfiguredExtensions(
            'nvl-mail-notifications.extensions.notifiable_type_providers',
            ProvidesNotifiableTypes::class,
            ProvidesNotifiableTypes::TAG,
        );
        $this->registerConfiguredExtensions(
            'nvl-mail-notifications.extensions.webhook_managers',
            RemoteWebhookManager::class,
            RemoteWebhookManager::TAG,
        );
        $this->registerConfiguredExtensions(
            'nvl-mail-notifications.extensions.scheduled_message_factories',
            ScheduledMessageFactory::class,
            ScheduledMessageFactory::TAG,
        );

        $this->app->singleton(TrackingEligibility::class);
        $this->app->singleton(MailTestingInterceptor::class);
        $this->app->singleton(SymfonyMessageIdResolver::class);
        $this->app->singleton(
            MailTrackingEventDispatcher::class,
            static function (Application $app): MailTrackingEventDispatcher {
                $database = $app->make('db');

                return new MailTrackingEventDispatcher(
                    events: static fn (): Dispatcher => $app->make(Dispatcher::class),
                    commits: $app->make(ConnectionCommitCallbacks::class),
                    exceptions: $app->make(ExceptionHandler::class),
                    database: $database,
                );
            },
        );
        $this->app->singletonIf(
            SensitiveDataRedactor::class,
            $this->configuredImplementation(
                'nvl-mail-notifications.services.sensitive_data_redactor',
                SensitiveDataRedactor::class,
                DefaultSensitiveDataRedactor::class,
            ),
        );
        $this->app->singletonIf(
            TrackingLifecycle::class,
            $this->configuredImplementation(
                'nvl-mail-notifications.services.tracking_lifecycle',
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
            $this->app->singletonIf(
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
                $configuredTypes = config('nvl-mail-notifications.notifiable_types', []);

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
                $app->tagged(ScheduledMessageFactory::TAG),
                $app,
            ),
        );
        $this->app->singletonIf(ScheduledMailScheduler::class);
        $this->app->singletonIf(ScheduledMailSchedulerContract::class, static fn (Application $app): ScheduledMailSchedulerContract => $app->make(ScheduledMailScheduler::class));
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
                schedule: $app->make(Schedule::class),
                factories: static fn (): ScheduledMessageFactoryRegistry => $app->make(
                    ScheduledMessageFactoryRegistry::class,
                ),
            ),
        );
        $this->app->singleton(MailRetentionPruner::class);
        $this->app->singleton(MailAnonymizationConfiguration::class);
        $this->app->singleton(MailHistoryAnonymizer::class);
        $this->app->singleton(TrackingRuntime::class);
        $this->app->scoped(WebhookProcessor::class);
    }

    /**
     * Publish package resources and attach Laravel mail lifecycle listeners.
     */
    public function boot(): void
    {
        $this->app->make(GlobalNames::class)->translations('mail-notifications', __DIR__.'/../../lang', $this->app->make('translation.loader'));
        $this->publishes([
            __DIR__.'/../../lang' => lang_path('vendor/nvl-mail-notifications'),
        ], 'nvl-mail-notifications-translations');
        TrackingRuntimeBridge::clear();
        SensitiveStorageBridge::clear();
        SensitiveStorageBridge::use(
            $this->app->make(SensitiveStorageCodec::class),
        );

        if ($this->packageEnabled()) {
            $this->app->make(MailTestingInterceptor::class)->apply();
            $this->bootTracking();
        }

        $this->publishes([
            dirname(__DIR__, 2).'/config/nvl-mail-notifications.php' => config_path('nvl-mail-notifications.php'),
        ], 'nvl-mail-notifications-config');
        $this->publishesMigrations([
            dirname(__DIR__, 2).'/database/migrations' => database_path('migrations'),
        ], 'nvl-mail-notifications-migrations');
        $this->publishes([
            dirname(__DIR__, 2).'/resources/boost/skills' => base_path('.agents/skills'),
        ], 'nvl-mail-notifications-skills');
        $this->publishes([
            dirname(__DIR__, 2).'/resources/images/integrations' => public_path('images/integrations'),
        ], 'nvl-mail-notifications-assets');
        $this->publishes([
            dirname(__DIR__, 2).'/resources/adoption/mail-notifications.v1.example.json' => base_path('nvl-mail-notifications.adoption.json'),
        ], 'nvl-mail-notifications-adoption');
        if ($this->configurationBoolean(
            'nvl-mail-notifications.migrations.enabled',
            true,
        )) {
            $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                AdoptMailNotificationsCommand::class,
                AnonymizeMailNotificationsCommand::class,
                MailNotificationsDoctorCommand::class,
                PruneMailNotificationsCommand::class,
                ProcessScheduledMailCommand::class,
                RecoverScheduledMailCommand::class,
                RemoveRemoteWebhooksCommand::class,
                SyncRemoteWebhooksCommand::class,
            ]);
            if (config('nvl-mail-notifications.managed.enabled') === true) {
                $this->commands([
                    DispatchPendingMail::class,
                    ReconcileMailWebhooks::class,
                    RecoverClientWebhooks::class,
                    RecoverProviderSyncs::class,
                    PruneMailHistory::class,
                    PruneMessageAttachments::class,
                    PruneOrphanMailFiles::class,
                    QuarantineRestoredMail::class,
                    SyncMailIntegrations::class,
                ]);
            }
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
            'nvl-mail-notifications.enabled',
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
