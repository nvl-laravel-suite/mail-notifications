<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
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
    config()->set('nvl-mail-notifications.connection', 'alpha-mail-schema');
    DB::purge('alpha-mail-schema');
});

afterEach(function (): void {
    DB::purge('alpha-mail-schema');
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
