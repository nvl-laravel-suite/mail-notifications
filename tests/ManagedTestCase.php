<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Services\Delivery\MailMediaAuthorization;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;

/** Isolated managed engine consumer with only the package's required providers. */
abstract class ManagedTestCase extends CommittedEventTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), TranslatableServiceProvider::class, MediaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->extend(MediaAuthorization::class, static fn (MediaAuthorization $policy): MediaAuthorization => new MailMediaAuthorization($policy));
        $app['config']->set('nvl-mail-notifications.managed.enabled', true);
        $app['config']->set('nvl-mail-notifications.tracking.enabled', false);
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
        $app['config']->set('database.connections.sqlite.foreign_key_constraints', true);
        $app['config']->set('nvl-media.disk', 'local');
        $app['config']->set('nvl-media.private_disk', 'local');
        $app['config']->set('nvl-media.public_disk', 'public');
        $app['config']->set('nvl-media.allowed_disks', ['local', 'public']);
        $app['config']->set('nvl-media.delivery.disks', [
            'local' => ['public' => false, 'private' => ['mode' => 'application', 'allowed' => ['application', 'signed_application']], 'maximum_ttl' => 120],
            'public' => ['public' => ['mode' => 'direct', 'allowed' => ['direct', 'application']], 'private' => false],
        ]);
    }

    protected function migrateDatabases(): void
    {
        parent::migrateDatabases();

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::enableForeignKeyConstraints();
    }
}
