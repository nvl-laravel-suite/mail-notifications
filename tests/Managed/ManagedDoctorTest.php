<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\Services\ManagedDoctor;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Contracts\MediaContentScanner;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\DefaultMediaAuthorization;
use Nvl\Media\Services\NullMediaContentScanner;

it('appends healthy managed readiness checks without invoking storage or scanning', function (): void {
    $scanner = Mockery::mock(MediaContentScanner::class);
    $scanner->shouldNotReceive('scan');
    app()->instance(MediaContentScanner::class, $scanner);
    config()->set('queue.default', 'database');

    $checks = collect(app(MailNotificationsDoctor::class)->inspect())->filter(fn ($check): bool => str_starts_with($check->key, 'managed.'));

    expect($checks)->toHaveCount(6);
    expect($checks->where('passed', false))->toBeEmpty();
    expect($checks->firstWhere('key', 'managed.scanning')->message)->toContain('No scan was performed', 'malware safety is not established');
});

it('skips managed diagnostics and preserves existing doctor output when disabled', function (): void {
    config()->set('nvl-mail-notifications.managed.enabled', false);
    $before = app(MailNotificationsDoctor::class)->inspect();
    config()->set('nvl-media.models.media', 'UnavailableMediaModel');
    config()->set('nvl-media.connection', 'unavailable-managed-media');
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    try {
        expect(app(ManagedDoctor::class)->inspect())->toBe([]);
        expect($queries)->toBe(0);
        expect(app(MailNotificationsDoctor::class)->inspect())->toEqual($before);
    } finally {
        config()->set('nvl-media.models.media', Media::class);
        config()->set('nvl-media.connection', null);
    }
});

it('reports a missing canonical managed table', function (): void {
    Schema::drop(MailNotificationsTables::get('provider_sync_pages'));

    $check = collect(app(ManagedDoctor::class)->inspect())->firstWhere('key', 'managed.schema');

    expect($check->passed)->toBeFalse();
    expect($check->message)->toContain('complete initial mail schema', 'nullable Media links');
});

it('reports a missing nullable Media link', function (string $table): void {
    Schema::table(MailNotificationsTables::get($table), fn (Blueprint $blueprint) => $blueprint->renameColumn('media_id', 'missing_media_id'));
    try {
        $check = collect(app(ManagedDoctor::class)->inspect())->firstWhere('key', 'managed.schema');

        expect($check->passed)->toBeFalse();
    } finally {
        Schema::table(MailNotificationsTables::get($table), fn (Blueprint $blueprint) => $blueprint->renameColumn('missing_media_id', 'media_id'));
    }
})->with(['catalog' => 'assets', 'message snapshot' => 'message_assets']);

it('reports a non-nullable Media link', function (): void {
    Schema::table(MailNotificationsTables::get('assets'), fn (Blueprint $blueprint) => $blueprint->string('media_id', 36)->nullable(false)->change());

    $check = collect(app(ManagedDoctor::class)->inspect())->firstWhere('key', 'managed.schema');

    expect($check->passed)->toBeFalse();
});

it('rejects a separate actual Media or owner-slot ledger connection', function (string $key): void {
    config()->set('database.connections.separate-managed-media', config('database.connections.testing'));
    config()->set($key, 'separate-managed-media');

    $check = collect(app(ManagedDoctor::class)->inspect())->firstWhere('key', 'managed.connections');

    expect($check->passed)->toBeFalse();
    expect($check->message)->toContain('same actual Laravel connection');
})->with([
    'Media root' => 'nvl-media.connection',
    'slot ledger' => 'nvl-media.connections.owner_slot_operations',
]);

it('reports invalid managed disk declarations without storage I/O', function (string $key, mixed $value): void {
    config()->set($key, $value);

    $check = collect(app(ManagedDoctor::class)->inspect())->firstWhere('key', 'managed.disks');

    expect($check->passed)->toBeFalse();
})->with([
    'shared private/public disk' => ['nvl-mail-notifications.managed.storage.public_disk', 'local'],
    'undefined disk' => ['nvl-mail-notifications.managed.storage.disk', 'undefined-mail'],
    'unadmitted public disk' => ['nvl-media.allowed_disks', ['local']],
    'disabled private visibility' => ['nvl-media.delivery.disks.local.private', false],
    'disabled public visibility' => ['nvl-media.delivery.disks.public.public', false],
    'direct-only public delivery' => ['nvl-media.delivery.disks.public.public', ['mode' => 'direct', 'allowed' => ['direct']]],
    'empty cleanup root' => ['nvl-media.root_folders.local', ''],
]);

it('rejects an implicit default Media authorization policy', function (): void {
    app()->instance(MediaAuthorization::class, new DefaultMediaAuthorization);

    $check = collect(app(ManagedDoctor::class)->inspect())->firstWhere('key', 'managed.authorization');

    expect($check->passed)->toBeFalse();
    expect($check->message)->toContain('explicit MailMediaAuthorization');
});

it('describes custom policy wiring without exercising or claiming actor permissions', function (): void {
    $policy = Mockery::mock(MediaAuthorization::class);
    $policy->shouldNotReceive('allows');
    app()->instance(MediaAuthorization::class, $policy);

    $check = collect(app(ManagedDoctor::class)->inspect())->firstWhere('key', 'managed.authorization');

    expect($check->passed)->toBeTrue();
    expect($check->message)->toContain('custom host', 'Runtime actor permissions are not verified');
});

it('uses fixed safe diagnostics when configuration resolution throws', function (): void {
    $secret = 'mysql://admin:never-print-this@database.invalid/private';
    config()->set('nvl-media.models.media', $secret);
    app()->bind(MediaAuthorization::class, static fn () => throw new RuntimeException($secret));

    $checks = app(ManagedDoctor::class)->inspect();

    expect(json_encode($checks, JSON_THROW_ON_ERROR))->not->toContain($secret, 'never-print-this');
    expect(collect($checks)->firstWhere('key', 'managed.connections')->passed)->toBeFalse();
    expect(collect($checks)->firstWhere('key', 'managed.authorization')->passed)->toBeFalse();
});

it('reports unresolved scanner configuration as an ingestion readiness error', function (bool $constructorFails): void {
    if ($constructorFails) {
        app()->bind(MediaContentScanner::class, static fn (): MediaContentScanner => throw new RuntimeException('private-scanner-token'));
    } else {
        config()->set('nvl-media.content_scanner', 'UnavailableConfiguredMediaScanner');
        app()->bind(MediaContentScanner::class, 'UnavailableConfiguredMediaScanner');
    }

    $check = collect(app(ManagedDoctor::class)->inspect())->firstWhere('key', 'managed.scanning');

    expect($check->passed)->toBeFalse();
    expect($check->severity)->toBe('error');
    expect($check->message)->toContain('could not be resolved', 'no scan was performed')->not->toContain('private-scanner-token');
})->with(['constructor failure' => true, 'unavailable configured scanner' => false]);

it('requires an explicit no-op override when Media scanning is required', function (bool $allowNoop): void {
    app()->instance(MediaContentScanner::class, new NullMediaContentScanner);
    config()->set('nvl-media.scanner.required', true);
    config()->set('nvl-media.scanner.allow_noop', $allowNoop);

    $check = collect(app(ManagedDoctor::class)->inspect())->firstWhere('key', 'managed.scanning');

    expect($check->passed)->toBe($allowNoop);
    expect($check->severity)->toBe($allowNoop ? 'info' : 'error');
    expect($check->message)->toContain('No scan was performed');
    if ($allowNoop) {
        expect($check->message)->toContain('No external Media scanner', 'malware safety is not established');
    }
})->with(['no implicit override' => false, 'explicit no-op override' => true]);
