<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use LogicException;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots MySQL-family concurrency tests on an explicitly disposable database.
 */
abstract class MySqlConcurrencyTestCase extends Orchestra
{
    private const string SAFE_DATABASE_PREFIX =
        'nvl_mail_notifications_test_';

    /**
     * Rebuild the dedicated MySQL-family schema before every concurrency proof.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection();

        if (! in_array(
            $connection->getDriverName(),
            ['mysql', 'mariadb'],
            true,
        )) {
            return;
        }

        $database = $connection->getDatabaseName();

        if (! str_starts_with($database, self::SAFE_DATABASE_PREFIX)) {
            throw new LogicException(sprintf(
                'MySQL-family concurrency tests refuse migrate:fresh on database [%s]; use a disposable database whose name starts with [%s].',
                $database,
                self::SAFE_DATABASE_PREFIX,
            ));
        }

        $this->artisan('migrate:fresh', [
            '--database' => $connection->getName(),
            '--force' => true,
        ])->assertExitCode(0);

        RefreshDatabaseState::$migrated = false;
    }

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
     * Configure the isolated mail transport used by the package services.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.mailers.array', [
            'transport' => 'array',
        ]);
        $app['config']->set('mail.from', [
            'address' => 'sender@example.test',
            'name' => 'Example Sender',
        ]);
        $app['config']->set('mail.testing', [
            'enabled' => false,
        ]);
        $app['config']->set('app.name', 'Mail Package Concurrency Test');
        $app['config']->set('app.url', 'https://mail-package.test');
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('database.connections.mysql.timezone', '+00:00');
        $app['config']->set('database.connections.mariadb.timezone', '+00:00');
    }
}
