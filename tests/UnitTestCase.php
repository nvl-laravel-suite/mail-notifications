<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Lightweight container test case for database-free Mail Notifications unit tests.
 */
abstract class UnitTestCase extends Orchestra
{
    /**
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

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'nvl-mail-notifications.migrations.enabled' => false,
            'app.timezone' => 'UTC',
            'database.connections.pgsql.timezone' => 'UTC',
            'mail.default' => 'array',
            'mail.mailers.array' => ['transport' => 'array'],
            'mail.mailers.smtp-test' => ['transport' => 'array'],
            'mail.from' => ['address' => 'sender@example.test', 'name' => 'Example Sender'],
            'mail.markdown.theme' => 'default',
            'mail.markdown.paths' => [__DIR__.'/Fixtures/views'],
            'mail.testing' => ['enabled' => false],
            'app.name' => 'Mail Package Test',
            'app.url' => 'https://mail-package.test',
        ]);
        $app['view']->addNamespace('mail-notifications-tests', __DIR__.'/Fixtures/views');
    }
}
