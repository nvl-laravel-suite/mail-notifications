<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Nvl\MailNotifications\Contracts\ProviderAdapter;
use Nvl\MailNotifications\Contracts\SensitiveDataRedactor;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\ProviderRegistry;
use Nvl\MailNotifications\Services\WebhookProcessor;
use Nvl\MailNotifications\Tests\Fixtures\PluggedProviderAdapter;
use Nvl\MailNotifications\Tests\Fixtures\PluggedSensitiveDataRedactor;
use Nvl\MailNotifications\Tests\Fixtures\PluggedTrackingLifecycle;
use Nvl\MailNotifications\Tests\Fixtures\TestTrackable;
use Nvl\MailNotifications\Tests\Fixtures\TrackedMail;
use Nvl\MailNotifications\ValueObjects\WebhookRequest;

it('resolves configured services, provider adapters, and notifiable aliases', function () {
    $notifiableTypes = app(MailNotificationNotifiableTypeRegistry::class);

    expect(app(TrackingLifecycle::class))
        ->toBeInstanceOf(PluggedTrackingLifecycle::class)
        ->and(app(SensitiveDataRedactor::class))
        ->toBeInstanceOf(PluggedSensitiveDataRedactor::class)
        ->and(app(ProviderRegistry::class)->resolve('plugged-provider'))
        ->toBeInstanceOf(PluggedProviderAdapter::class)
        ->and($notifiableTypes->resolve('configured-trackable'))
        ->toBe(TestTrackable::class)
        ->and($notifiableTypes->resolve('provided-trackable'))
        ->toBe(TestTrackable::class);
});

it('uses configured adapter and standalone message identifier resolvers', function () {
    Mail::mailer('plugged-provider')
        ->to('adapter@example.test')
        ->send(new TrackedMail(category: 'test.plugged-adapter'));
    Mail::mailer('plugged-resolver')
        ->to('resolver@example.test')
        ->send(new TrackedMail(category: 'test.plugged-resolver'));

    $adapterNotification = MailNotification::query()
        ->where('message_category', 'test.plugged-adapter')
        ->sole();
    $resolverNotification = MailNotification::query()
        ->where('message_category', 'test.plugged-resolver')
        ->sole();

    expect($adapterNotification)
        ->provider->toBe('plugged-provider')
        ->provider_message_id->not->toBeNull()
        ->and($adapterNotification->metadata)
        ->toHaveKey('plugged_redactor', true)
        ->and($resolverNotification)
        ->provider->toBe('plugged-resolver')
        ->provider_message_id->not->toBeNull();
});

it('verifies and applies provider webhooks through one configured adapter', function () {
    Mail::mailer('plugged-provider')
        ->to('adapter@example.test')
        ->send(new TrackedMail(category: 'test.plugged-webhook'));

    $notification = MailNotification::query()->sole();
    $httpRequest = Request::create(
        uri: '/mail-webhooks/plugged-provider',
        method: 'POST',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PLUGGED_SIGNATURE' => 'valid-signature',
        ],
        content: json_encode([
            'event_id' => 'plugged-event-1',
            'message_id' => $notification->provider_message_id,
        ], JSON_THROW_ON_ERROR),
    );
    $result = app(WebhookProcessor::class)->process(
        provider: 'plugged-provider',
        request: WebhookRequest::fromLaravelRequest(
            provider: 'plugged-provider',
            request: $httpRequest,
        ),
    );

    expect($result)
        ->applied->toBeTrue()
        ->currentStatus->toBe(MailDeliveryStatus::Delivered)
        ->and($notification->refresh()->status)
        ->toBe(MailDeliveryStatus::Delivered);
});

it('enforces the configured webhook payload boundary before adapter processing', function () {
    config()->set('mail-notifications.webhooks.max_payload_bytes', 4);

    expect(fn () => app(WebhookProcessor::class)->process(
        provider: 'plugged-provider',
        request: new WebhookRequest(
            provider: 'plugged-provider',
            body: '12345',
            headers: [
                'x-plugged-signature' => 'valid-signature',
            ],
        ),
    ))->toThrow(
        DomainException::class,
        'exceeds the configured size limit',
    );
});

it('honors the global and webhook-specific runtime switches', function (
    string $configKey,
) {
    config()->set($configKey, false);

    expect(fn () => app(WebhookProcessor::class)->process(
        provider: 'plugged-provider',
        request: new WebhookRequest(
            provider: 'plugged-provider',
            body: '{}',
            headers: [],
        ),
    ))->toThrow(
        MailTrackingException::class,
        'webhook processing is disabled',
    );
})->with([
    'global package switch' => 'mail-notifications.enabled',
    'webhook processing switch' => 'mail-notifications.webhooks.enabled',
]);

it('rejects malformed webhook runtime switches', function (string $configKey) {
    config()->set($configKey, 'false');

    expect(fn () => app(WebhookProcessor::class)->enabled())
        ->toThrow(MailTrackingException::class, 'must be a boolean');
})->with([
    'global package switch' => 'mail-notifications.enabled',
    'webhook processing switch' => 'mail-notifications.webhooks.enabled',
]);

it('rejects webhook route and request provider mismatches', function () {
    expect(fn () => app(WebhookProcessor::class)->process(
        provider: 'plugged-provider',
        request: new WebhookRequest(
            provider: 'another-provider',
            body: '{}',
            headers: [],
        ),
    ))->toThrow(
        DomainException::class,
        'does not match the selected adapter',
    );
});

it('does not normalize provider webhooks before signature verification', function () {
    expect(fn () => app(WebhookProcessor::class)->process(
        provider: 'plugged-provider',
        request: new WebhookRequest(
            provider: 'plugged-provider',
            body: '{"event_id":"unsafe","message_id":"unsafe"}',
            headers: [
                'x-plugged-signature' => 'invalid-signature',
            ],
        ),
    ))->toThrow(
        DomainException::class,
        'webhook signature is invalid',
    );
});

it('requires registered adapters to provide both webhook contracts', function () {
    $adapter = new class implements ProviderAdapter
    {
        public function name(): string
        {
            return 'message-id-only';
        }
    };
    $processor = new WebhookProcessor(
        providers: new ProviderRegistry([$adapter]),
        lifecycle: app(TrackingLifecycle::class),
        config: app(Repository::class),
    );

    expect(fn () => $processor->process(
        provider: 'message-id-only',
        request: new WebhookRequest(
            provider: 'message-id-only',
            body: '{}',
            headers: [],
        ),
    ))->toThrow(
        MailTrackingException::class,
        'must implement webhook verification and normalization',
    );
});

it('fails closed for invalid webhook size configuration', function (
    mixed $limit,
) {
    config()->set('mail-notifications.webhooks.max_payload_bytes', $limit);

    expect(fn () => app(WebhookProcessor::class)->maximumPayloadBytes())
        ->toThrow(
            MailTrackingException::class,
            'must be a positive integer',
        );
})->with([
    'zero' => 0,
    'negative' => -1,
    'numeric string' => '4096',
]);
