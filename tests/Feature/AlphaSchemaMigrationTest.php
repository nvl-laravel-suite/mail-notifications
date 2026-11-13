<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;

/** Fresh alpha schema, deliberately independent from package migration history. */
function alphaMailSchema(): Migration
{
    return require dirname(__DIR__, 2).'/database/migrations/2026_11_02_000000_nvl_mail_notifications_create_schema.php';
}

beforeEach(function (): void {
    config()->set('database.connections.alpha-mail-schema', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    config()->set('database.connections.alpha-mail-history', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    config()->set('nvl-mail-notifications.connection', 'alpha-mail-schema');
    DB::purge('alpha-mail-schema');

    if (in_array('mail-migration-history', $this->groups(), true) && getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql') {
        $historyDatabase = getenv('NVL_OWNERSHIP_TEST_DATABASE');
        $mailDatabase = getenv('NVL_OWNERSHIP_TEST_REMOTE_DATABASE');
        foreach ([$historyDatabase, $mailDatabase] as $database) {
            if (! is_string($database) || preg_match('/^nvl_own_test_mail_notifications_[a-f0-9]{8}_(primary|remote)$/D', $database) !== 1) {
                throw new RuntimeException('Mail migration history tests require dedicated package runner databases.');
            }
        }
        if ($historyDatabase === $mailDatabase) {
            throw new RuntimeException('Mail migration history tests require separate databases.');
        }

        $connection = [
            'driver' => 'pgsql',
            'host' => getenv('NVL_OWNERSHIP_TEST_HOST') ?: '/tmp',
            'port' => getenv('NVL_OWNERSHIP_TEST_PORT') ?: '5432',
            'username' => getenv('NVL_OWNERSHIP_TEST_USERNAME') ?: get_current_user(),
            'password' => getenv('NVL_OWNERSHIP_TEST_PASSWORD') ?: '',
            'charset' => 'utf8',
            'search_path' => 'public',
            'timezone' => 'UTC',
        ];
        config()->set('database.connections.alpha-mail-history', [...$connection, 'database' => $historyDatabase]);
        config()->set('database.connections.alpha-mail-schema', [...$connection, 'database' => $mailDatabase]);
        DB::connection('alpha-mail-history')->beginTransaction();
        DB::connection('alpha-mail-schema')->beginTransaction();
    }
});

afterEach(function (): void {
    foreach (['alpha-mail-schema', 'alpha-mail-history'] as $name) {
        $connection = DB::getConnections()[$name] ?? null;
        if ($connection?->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::purge($name);
    }
});

it('ships one complete alpha migration and no incremental or tenancy migrations', function (): void {
    $paths = glob(dirname(__DIR__, 2).'/database/*migrations/*.php') ?: [];
    expect($paths)->toHaveCount(1);
    expect(basename($paths[0]))->toBe('2026_11_02_000000_nvl_mail_notifications_create_schema.php');
});

it('creates every package table including initial Media and ownership columns with delivery disabled', function (): void {
    config()->set('nvl-mail-notifications.managed.enabled', false);
    alphaMailSchema()->up();
    $schema = Schema::connection('alpha-mail-schema');
    foreach ((new ReflectionClass(MailNotificationsTables::class))->getConstants() as $table) {
        expect($schema->hasTable($table))->toBeTrue();
    }
    foreach ([MailNotificationsTables::Assets, MailNotificationsTables::MessageAssets] as $table) {
        expect($schema->hasColumn($table, 'media_id'))->toBeTrue();
        expect(array_any($schema->getIndexes($table), static fn (array $index): bool => $index['columns'] === ['media_id']))->toBeTrue();
    }
    foreach ([MailNotificationsTables::Notifications, MailNotificationsTables::ScheduledMessages] as $table) {
        expect($schema->hasColumn($table, 'tenant_id'))->toBeTrue();
        expect($schema->hasColumn($table, 'ownership_key'))->toBeTrue();
    }
    expect($schema->hasColumn(MailNotificationsTables::Events, 'tenant_id'))->toBeTrue();
    expect($schema->hasColumn(MailNotificationsTables::ScheduledMessages, 'tenant_envelope'))->toBeTrue();
});

it('rolls back and reapplies all mail tables without touching another packages data', function (): void {
    $schema = Schema::connection('alpha-mail-schema');
    $schema->create('external_media', static fn (Blueprint $table) => $table->string('id')->primary());
    DB::connection('alpha-mail-schema')->table('external_media')->insert(['id' => 'keep']);
    alphaMailSchema()->up();
    alphaMailSchema()->down();
    foreach ((new ReflectionClass(MailNotificationsTables::class))->getConstants() as $table) {
        expect($schema->hasTable($table))->toBeFalse();
    }
    expect(DB::connection('alpha-mail-schema')->table('external_media')->value('id'))->toBe('keep');
    alphaMailSchema()->up();
    expect($schema->hasTable(MailNotificationsTables::EmailMessages))->toBeTrue();
    expect($schema->hasTable(MailNotificationsTables::ScheduledMessages))->toBeTrue();
});

it('rejects an existing mail table before partially creating or adopting a schema', function (): void {
    $schema = Schema::connection('alpha-mail-schema');
    $schema->create(MailNotificationsTables::EmailMessages, static fn (Blueprint $table) => $table->string('id')->primary());
    DB::connection('alpha-mail-schema')->table(MailNotificationsTables::EmailMessages)->insert(['id' => 'keep']);
    expect(fn () => alphaMailSchema()->up())->toThrow(LogicException::class, 'already exists');
    expect($schema->hasTable(MailNotificationsTables::Notifications))->toBeFalse();
    expect($schema->hasTable(MailNotificationsTables::Owners))->toBeFalse();
    expect(DB::connection('alpha-mail-schema')->table(MailNotificationsTables::EmailMessages)->value('id'))->toBe('keep');
});

it('rejects colliding configured table names before writing any schema', function (): void {
    config()->set('nvl-mail-notifications.tables.notifications', 'alpha_collision');
    config()->set('nvl-mail-notifications.tables.events', 'alpha_collision');
    expect(fn () => alphaMailSchema()->up())->toThrow(LogicException::class, 'must be distinct');
    expect(Schema::connection('alpha-mail-schema')->getTableListing())->toBe([]);
});

it('uses configured table names consistently for managed foreign keys and rollback', function (): void {
    config()->set('nvl-mail-notifications.tables.clients', 'alpha_custom_clients');
    config()->set('nvl-mail-notifications.tables.notifications', 'alpha_custom_notifications');
    alphaMailSchema()->up();
    $schema = Schema::connection('alpha-mail-schema');
    expect($schema->hasTable('alpha_custom_clients'))->toBeTrue();
    expect($schema->hasTable('alpha_custom_notifications'))->toBeTrue();
    expect($schema->hasTable(MailNotificationsTables::Clients))->toBeFalse();
    expect(array_any($schema->getForeignKeys(MailNotificationsTables::Assets), static fn (array $foreign): bool => $foreign['foreign_table'] === 'alpha_custom_clients'))->toBeTrue();
    alphaMailSchema()->down();
    expect($schema->hasTable('alpha_custom_clients'))->toBeFalse();
    expect($schema->hasTable('alpha_custom_notifications'))->toBeFalse();
});

it('accepts exact history written by Laravel for a separate mail schema connection', function (bool $explicitDatabase, string $defaultConnection): void {
    $options = ['--path' => [dirname(__DIR__, 2).'/database/migrations'], '--realpath' => true];
    if ($explicitDatabase) {
        $options['--database'] = 'alpha-mail-schema';
    }

    DB::usingConnection('alpha-mail-history', function () use ($options): void {
        $this->artisan('migrate', $options)->assertSuccessful();
    });

    $migrator = app(Migrator::class);
    $repository = $migrator->getRepository();
    $originalDefault = DB::getDefaultConnection();
    $originalMigratorConnection = $migrator->getConnection();
    $repository->setSource($originalDefault);

    try {
        DB::usingConnection($defaultConnection, function () use ($defaultConnection, $migrator, $originalDefault, $originalMigratorConnection, $repository): void {
            $checks = collect(app(MailNotificationsDoctor::class)->inspect());

            expect($checks->firstWhere('key', 'schema.migrations')->passed)->toBeTrue()
                ->and($checks->firstWhere('key', 'schema.definitions')->passed)->toBeTrue()
                ->and(DB::getDefaultConnection())->toBe($defaultConnection)
                ->and($migrator->getConnection())->toBe($originalMigratorConnection)
                ->and($repository->getConnection()->getName())->toBe($originalDefault);
        });
    } finally {
        $repository->setSource($originalMigratorConnection);
    }
})->with([
    'Laravel default repository' => [false, 'alpha-mail-history'],
    'explicit Mail repository' => [true, 'alpha-mail-history'],
    'explicit Mail repository with an unavailable default' => [true, 'alpha-missing-default'],
])->group('mail-migration-history');

it('requires exact migration ownership in a supported repository even when the mail schema is healthy', function (?string $recordedMigration, string $defaultConnection, string $expectedMessage): void {
    alphaMailSchema()->up();
    $migrator = app(Migrator::class);
    $repository = clone $migrator->getRepository();
    if ($recordedMigration !== null) {
        $repository->setSource('alpha-mail-schema');
        $repository->createRepository();
        $repository->log($recordedMigration, 1);
    }

    $originalDefault = DB::getDefaultConnection();
    $originalMigratorConnection = $migrator->getConnection();
    $migrator->getRepository()->setSource($originalDefault);
    try {
        DB::usingConnection($defaultConnection, function () use ($defaultConnection, $expectedMessage, $migrator, $originalDefault, $originalMigratorConnection): void {
            $checks = collect(app(MailNotificationsDoctor::class)->inspect());
            $history = $checks->firstWhere('key', 'schema.migrations');

            expect($history->passed)->toBeFalse()
                ->and($history->message)->toContain($expectedMessage)
                ->and($checks->firstWhere('key', 'schema.definitions')->passed)->toBeTrue()
                ->and(DB::getDefaultConnection())->toBe($defaultConnection)
                ->and($migrator->getConnection())->toBe($originalMigratorConnection)
                ->and($migrator->getRepository()->getConnection()->getName())->toBe($originalDefault);
        });
    } finally {
        $migrator->getRepository()->setSource($originalMigratorConnection);
    }
})->with([
    'missing repositories' => [null, 'alpha-mail-history', 'migration repository does not exist'],
    'different timestamp' => ['2099_01_01_000000_nvl_mail_notifications_create_schema', 'alpha-mail-history', '2026_11_02_000000_nvl_mail_notifications_create_schema'],
    'unavailable default and missing Mail repository' => [null, 'alpha-missing-default', 'migration history is unavailable'],
])->group('mail-migration-history');

it('inspects a host-owned mail schema without requiring a migration repository', function (): void {
    alphaMailSchema()->up();
    config()->set('nvl-mail-notifications.migrations.enabled', false);

    DB::usingConnection('alpha-mail-history', function (): void {
        $checks = collect(app(MailNotificationsDoctor::class)->inspect());

        expect($checks->firstWhere('key', 'schema.migrations')->passed)->toBeTrue()
            ->and($checks->firstWhere('key', 'schema.definitions')->passed)->toBeTrue();
    });
})->group('mail-migration-history');

it('reports drifted alpha column types nullability and defaults through the runtime doctor', function (string $change): void {
    alphaMailSchema()->up();
    Schema::connection('alpha-mail-schema')->table(MailNotificationsTables::Notifications, static function (Blueprint $table) use ($change): void {
        match ($change) {
            'type' => $table->integer('mailer')->change(),
            'nullable' => $table->string('mailer', 128)->nullable()->change(),
            'default' => $table->string('status', 32)->default('not-a-status')->change(),
        };
    });
    $check = collect(app(MailNotificationsDoctor::class)->inspect())->firstWhere('key', 'schema.definitions');
    expect($check)->not->toBeNull();
    expect($check->passed)->toBeFalse();
})->with(['type', 'nullable', 'default']);

it('reports missing alpha uniqueness through the runtime doctor', function (): void {
    alphaMailSchema()->up();
    Schema::connection('alpha-mail-schema')->table(MailNotificationsTables::Notifications, static function (Blueprint $table): void {
        $table->dropUnique('mail_notifications_provider_message_unique');
    });
    $check = collect(app(MailNotificationsDoctor::class)->inspect())->firstWhere('key', 'schema.constraints');
    expect($check->passed)->toBeFalse();
});

it('reports a missing provider event cascade through the runtime doctor', function (): void {
    alphaMailSchema()->up();
    Schema::connection('alpha-mail-schema')->table(MailNotificationsTables::Events, static function (Blueprint $table): void {
        $table->dropForeign(['mail_notification_id']);
    });
    $check = collect(app(MailNotificationsDoctor::class)->inspect())->firstWhere('key', 'schema.constraints');
    expect($check->passed)->toBeFalse();
});

it('reports a missing alpha operational index through the runtime doctor', function (): void {
    alphaMailSchema()->up();
    Schema::connection('alpha-mail-schema')->table(MailNotificationsTables::Notifications, static function (Blueprint $table): void {
        $table->dropIndex('mail_notifications_status_created_index');
    });
    $check = collect(app(MailNotificationsDoctor::class)->inspect())->firstWhere('key', 'schema.indexes');
    expect($check->passed)->toBeFalse();
});
