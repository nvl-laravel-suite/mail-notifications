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

it('prefers canonical delivery environment names while retaining legacy fallbacks', function (string $suffix, string $path, string $legacyValue, mixed $legacyExpected, string $canonicalValue, mixed $canonicalExpected, string $configuration = 'config'): void {
    $legacy = 'MAIL_'.$suffix;
    $canonical = 'NVL_MAIL_NOTIFICATIONS_'.$suffix;
    $previousLegacy = getenv($legacy);
    $previousCanonical = getenv($canonical);
    putenv($legacy.'='.$legacyValue);
    putenv($canonical);

    try {
        $defaults = require dirname(__DIR__, 2).'/'.$configuration.'/nvl-mail-notifications.php';
        expect(data_get($defaults, 'managed.'.$path))->toBe($legacyExpected);

        putenv($canonical.'='.$canonicalValue);
        $defaults = require dirname(__DIR__, 2).'/'.$configuration.'/nvl-mail-notifications.php';
        expect(data_get($defaults, 'managed.'.$path))->toBe($canonicalExpected);
    } finally {
        putenv($previousLegacy === false ? $legacy : $legacy.'='.$previousLegacy);
        putenv($previousCanonical === false ? $canonical : $canonical.'='.$previousCanonical);
    }
})->with([
    ['DELIVERY_ENABLED', 'delivery.enabled', 'true', true, 'false', false],
    ['CONTENT_MAX_HTML_BYTES', 'content.max_html_bytes', '1024', 1024, '2048', 2048],
    ['WEBHOOK_QUEUE_CONNECTION', 'webhooks.connection', 'redis', 'redis', 'database', 'database'],
    ['WEBHOOK_QUEUE', 'webhooks.queue', 'old-webhooks', 'old-webhooks', 'new-webhooks', 'new-webhooks'],
    ['WEBHOOK_LOCAL_ORIGINS', 'webhooks.local_origins', 'http://old.test', ['http://old.test'], 'http://new.test, http://other.test', ['http://new.test', 'http://other.test']],
    ['WEBHOOK_LOCAL_ENVIRONMENTS', 'webhooks.local_environments', 'local', ['local'], 'local, testing', ['local', 'testing']],
    ['AUTH_REQUESTS_PER_MINUTE', 'authentication.requests_per_minute', '100', '100', '200', '200'],
    ['STORAGE_DISK', 'storage.disk', 'old-private', 'old-private', 'new-private', 'new-private'],
    ['STORAGE_PUBLIC_DISK', 'storage.public_disk', 'old-public', 'old-public', 'new-public', 'new-public'],
    ['ORPHAN_GRACE_HOURS', 'storage.orphan_grace_hours', '12', 12, '36', 36],
    ['ATTACHMENT_RETENTION_HOURS', 'attachments.retention_hours', '48', 48, '96', 96],
    ['MAINTENANCE_BATCH_SIZE', 'maintenance.batch_size', '100', 100, '200', 200],
    ['MAINTENANCE_MAX_SECONDS', 'maintenance.max_seconds', '10', 10, '20', 20],
    ['MAILERSEND_PER_MINUTE', 'sending_limits.providers.mailersend', '30', 30, '60', 60],
    ['SENDGRID_PER_MINUTE', 'sending_limits.providers.sendgrid', '30', 30, '60', 60],
    ['MAILGUN_PER_MINUTE', 'sending_limits.providers.mailgun', '30', 30, '60', 60],
    ['SMTP_PER_MINUTE', 'sending_limits.providers.smtp', '30', 30, '60', 60],
    ['DELIVERY_ENABLED', 'delivery.enabled', 'false', false, 'true', true],
    ['DELIVERY_ENABLED', 'delivery.enabled', 'true', true, 'false', false, 'resources/config'],
    ['STORAGE_DISK', 'storage.disk', 'old-private', 'old-private', 'new-private', 'new-private', 'resources/config'],
    ['STORAGE_PUBLIC_DISK', 'storage.public_disk', 'old-public', 'old-public', 'new-public', 'new-public', 'resources/config'],
]);
