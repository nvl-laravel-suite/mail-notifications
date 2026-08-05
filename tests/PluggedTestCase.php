<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\MailNotifications\Tests\Fixtures\PluggedMailNotificationsServiceProvider;

/**
 * Boots the package with a host's configuration-first extension set.
 */
abstract class PluggedTestCase extends TestCase
{
    /**
     * Register host configuration before the package provider.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PluggedMailNotificationsServiceProvider::class,
            MailNotificationsServiceProvider::class,
        ];
    }

    /**
     * Configure the fixture mailers used by configured extensions.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.mailers.plugged-provider', [
            'transport' => 'array',
        ]);
        $app['config']->set('mail.mailers.plugged-resolver', [
            'transport' => 'array',
        ]);
    }
}
