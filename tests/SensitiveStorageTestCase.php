<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\MailNotifications\Tests\Fixtures\SensitiveStorageHostServiceProvider;

/**
 * Boots the package with opt-in host sensitive-storage protection.
 */
abstract class SensitiveStorageTestCase extends TestCase
{
    /**
     * Register host protection configuration before the package provider.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SensitiveStorageHostServiceProvider::class,
            MailNotificationsServiceProvider::class,
        ];
    }
}
