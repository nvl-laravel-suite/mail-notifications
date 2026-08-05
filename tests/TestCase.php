<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Mail Notifications in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Register the package provider in the isolated application.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            MailNotificationsServiceProvider::class,
        ];
    }

    /**
     * Configure the isolated mail transport and fixture view namespace.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('database.connections.pgsql.timezone', 'UTC');
        $app['config']->set('database.connections.mysql.timezone', '+00:00');
        $app['config']->set('database.connections.mariadb.timezone', '+00:00');
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
