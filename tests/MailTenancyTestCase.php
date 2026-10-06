<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Nvl\Tenancy\Providers\TenancyServiceProvider;

/** Loads the optional Tenancy runtime for adoption registration tests. */
abstract class MailTenancyTestCase extends TestCase
{
    /**
     * Register the runtime before its Mail adopter.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TenancyServiceProvider::class, ...parent::getPackageProviders($app)];
    }
}
