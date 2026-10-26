<?php

declare(strict_types=1);

use Nvl\MailNotifications\Services\MailNotificationsDoctor;

it('reports a healthy native mail schema and migration ownership', function (): void {
    $checks = collect(app(MailNotificationsDoctor::class)->inspect());
    $migrations = $checks->firstWhere('key', 'schema.migrations');

    expect($migrations)->not->toBeNull()
        ->and($migrations->passed)->toBeTrue();
    $this->artisan('nvl:mail-notifications:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertSuccessful()->expectsOutputToContain('"healthy": true');
});

it('rejects malformed native feature switches', function (string $key): void {
    config()->set($key, 'false');
    $configuration = collect(app(MailNotificationsDoctor::class)->inspect())->firstWhere('key', 'configuration');

    expect($configuration)->not->toBeNull()
        ->and($configuration->passed)->toBeFalse()
        ->and($configuration->message)->toContain('must be a boolean');
})->with([
    'package' => 'nvl-mail-notifications.enabled',
    'tracking' => 'nvl-mail-notifications.tracking.enabled',
    'webhooks' => 'nvl-mail-notifications.webhooks.enabled',
]);
