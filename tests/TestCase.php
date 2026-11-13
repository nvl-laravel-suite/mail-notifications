<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Mail Notifications in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** Fingerprint of the providers and configuration the shared test schema was last migrated with. */
    public static ?string $migratedSchema = null;

    /**
     * Fingerprint what migrations depend on: registered providers, package configuration and connection settings.
     *
     * @param  list<class-string>  $providers
     */
    public static function schemaFingerprint(Application $app, array $providers): string
    {
        $config = $app->make('config');

        return md5(serialize([
            $providers,
            array_intersect_key($config->all(), array_flip(['nvl-mail-notifications', 'nvl-media', 'nvl-translatable', 'nvl-core', 'nvl-ownership'])),
            $config->get('database.connections.'.$config->get('database.default')),
        ]));
    }

    /** Rebuild instead of reusing a schema that another test base migrated differently. */
    protected function beforeRefreshingDatabase(): void
    {
        if (self::$migratedSchema !== self::schemaFingerprint($this->app, $this->getPackageProviders($this->app))
            || in_array('fresh-schema', $this->groups(), true)) {
            RefreshDatabaseState::$migrated = false;
        }
    }

    protected function afterRefreshingDatabase(): void
    {
        self::$migratedSchema = self::schemaFingerprint($this->app, $this->getPackageProviders($this->app));
    }

    /**
     * Register the package provider in the isolated application.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SupportServiceProvider::class,
            LocaleServiceProvider::class,
            MailNotificationsServiceProvider::class,
        ];
    }

    /**
     * Configure the isolated mail transport and fixture view namespace.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('hashing.bcrypt.rounds', 4);
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('database.connections.pgsql.timezone', 'UTC');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.mailers.array', [
            'transport' => 'array',
        ]);
        $app['config']->set('mail.mailers.smtp-test', [
            'transport' => 'array',
        ]);
        $app['config']->set('mail.from', [
            'address' => 'sender@example.test',
            'name' => 'Example Sender',
        ]);
        $app['config']->set('mail.markdown.theme', 'default');
        $app['config']->set('mail.markdown.paths', [
            __DIR__.'/Fixtures/views',
        ]);
        $app['config']->set('mail.testing', [
            'enabled' => false,
        ]);
        $app['config']->set('app.name', 'Mail Package Test');
        $app['config']->set('app.url', 'https://mail-package.test');
        $app['view']->addNamespace(
            'mail-notifications-tests',
            __DIR__.'/Fixtures/views',
        );
    }
}
