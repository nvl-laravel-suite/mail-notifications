<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Illuminate\Support\Facades\Event;
use Nvl\MailNotifications\Adapters\MailerSend\MailerSendAdapter;
use Nvl\MailNotifications\Contracts\ProviderAdapter;
use Nvl\MailNotifications\Contracts\ProviderMessageIdResolver;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Contracts\WebhookEventNormalizer;
use Nvl\MailNotifications\Contracts\WebhookSignatureVerifier;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Events\MailWebhookAcknowledged;
use Nvl\MailNotifications\Events\WebhookEventAmbiguous;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\Services\ProviderRegistry;
use Nvl\MailNotifications\Services\WebhookProcessor;
use Nvl\MailNotifications\Tests\Fixtures\AmbiguousTrackingLifecycle;
use Nvl\MailNotifications\ValueObjects\TransportResult;
use Nvl\MailNotifications\ValueObjects\VerifiedWebhook;
use Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement;
use Nvl\MailNotifications\ValueObjects\WebhookRequest;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-30T08:00:00Z');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * Create a completed MailerSend transport result with provider response headers.
 *
 * @param  array<string, string>  $headers
 */
function createMailerSendTransportResult(
    array $headers = [],
    string $mailer = 'mailersend',
): TransportResult {
    $email = (new Email)
        ->from('sender@example.test')
        ->to('recipient@example.test')
        ->subject('MailerSend adapter test')
        ->text('MailerSend adapter test');

    foreach ($headers as $name => $value) {
        $email->getHeaders()->addTextHeader($name, $value);
    }

    return new TransportResult(
        mailer: $mailer,
        message: new LaravelSentMessage(
            new SymfonySentMessage(
                $email,
                new Envelope(
                    new Address('sender@example.test'),
                    [new Address('recipient@example.test')],
                ),
            ),
        ),
    );
}

/**
 * Create a verified MailerSend v2 activity payload.
 *
 * @return array<string, mixed>
 */
function mailerSendActivityPayload(string $event): array
{
    return [
        'type' => $event,
        'created_at' => '2026-07-30T10:20:30+03:00',
        'data' => [
            'id' => 'event-'.$event,
            'message_id' => 'message-123@example.mailersend.net',
            'email_id' => 'email-123',
            'email' => 'recipient@example.test',
        ],
    ];
}

it('implements every optional provider capability without being registered by default', function () {
    $adapter = app(MailerSendAdapter::class);

    expect($adapter)
        ->toBeInstanceOf(ProviderAdapter::class)
        ->toBeInstanceOf(ProviderMessageIdResolver::class)
        ->toBeInstanceOf(WebhookSignatureVerifier::class)
        ->toBeInstanceOf(WebhookEventNormalizer::class)
        ->name()->toBe('mailersend')
        ->and(config('mail-notifications.extensions.provider_adapters'))->toBe([])
        ->and(app(ProviderRegistry::class)->all())->not->toHaveKey('mailersend');
});

it('registers the built-in adapter only when its class is configured', function () {
    config()->set(
        'mail-notifications.extensions.provider_adapters',
        [MailerSendAdapter::class],
    );

    (new MailNotificationsServiceProvider(app()))->register();

    expect(app(ProviderRegistry::class)->resolve('mailersend'))
        ->toBeInstanceOf(MailerSendAdapter::class);
});

it('prefers the transport-added MailerSend message identifier header', function () {
    $result = createMailerSendTransportResult([
        'X-Message-Id' => 'api-response-id@example.mailersend.net',
        'X-MailerSend-Message-Id' => '<transport-id@example.mailersend.net>',
    ]);
    $adapter = app(MailerSendAdapter::class);

    expect($adapter->supports($result))->toBeTrue()
        ->and($adapter->resolve($result))
        ->provider->toBe('mailersend')
        ->value->toBe('transport-id');
});

it('accepts the MailerSend API response header as a compatibility alias', function () {
    $result = createMailerSendTransportResult([
        'X-Message-Id' => 'api-response-id@example.mailersend.net',
    ]);
    $adapter = app(MailerSendAdapter::class);

    expect($adapter->resolve($result))
        ->provider->toBe('mailersend')
        ->value->toBe('api-response-id');
});

it('does not claim transport results without a MailerSend message identifier', function () {
    $adapter = app(MailerSendAdapter::class);
    $result = createMailerSendTransportResult();

    expect($adapter->supports($result))->toBeFalse()
        ->and($adapter->resolve($result))->toBeNull();
});

it('does not claim a generic message identifier from another mailer', function () {
    $adapter = app(MailerSendAdapter::class);
    $result = createMailerSendTransportResult(
        headers: ['X-Message-Id' => 'other-provider-id'],
        mailer: 'smtp',
    );

    expect($adapter->supports($result))->toBeFalse()
        ->and($adapter->resolve($result))->toBeNull();
});

it('accepts the transport-specific message identifier regardless of the mailer alias', function () {
    $adapter = app(MailerSendAdapter::class);
    $result = createMailerSendTransportResult(
        headers: ['X-MailerSend-Message-Id' => 'mailersend-id'],
        mailer: 'custom-mailer-alias',
    );

    expect($adapter->resolve($result))
        ->provider->toBe('mailersend')
        ->value->toBe('mailersend-id');
});

it('verifies the official signature against the exact raw body', function () {
    config()->set(
        'mail-notifications.providers.mailersend.signing_secret',
        'webhook-signing-secret',
    );
    $body = json_encode(
        mailerSendActivityPayload('delivered'),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );
    $request = new WebhookRequest(
        provider: 'mailersend',
        body: $body,
        headers: [
            'Signature' => hash_hmac('sha256', $body, 'webhook-signing-secret'),
        ],
    );

    expect(app(MailerSendAdapter::class)->verify($request)->payload)
        ->toBe(mailerSendActivityPayload('delivered'));
});

it('rejects a signature produced for different raw bytes', function () {
    config()->set(
        'mail-notifications.providers.mailersend.signing_secret',
        'webhook-signing-secret',
    );
    $signedBody = '{"type":"delivered","created_at":"2026-07-30T07:50:00Z"}';
    $receivedBody = '{"created_at":"2026-07-30T07:50:00Z","type":"delivered"}';
    $request = new WebhookRequest(
        provider: 'mailersend',
        body: $receivedBody,
        headers: [
            'Signature' => hash_hmac('sha256', $signedBody, 'webhook-signing-secret'),
        ],
    );

    expect(fn () => app(MailerSendAdapter::class)->verify($request))
        ->toThrow(DomainException::class, 'signature is invalid');
});

it('supports explicitly configured signature header aliases', function () {
    config()->set([
        'mail-notifications.providers.mailersend.signing_secret' => 'webhook-signing-secret',
        'mail-notifications.providers.mailersend.signature_headers' => [
            'X-Forwarded-MailerSend-Signature',
        ],
    ]);
    $body = json_encode(mailerSendActivityPayload('sent'), JSON_THROW_ON_ERROR);
    $request = new WebhookRequest(
        provider: 'mailersend',
        body: $body,
        headers: [
            'X-Forwarded-MailerSend-Signature' => hash_hmac(
                'sha256',
                $body,
                'webhook-signing-secret',
            ),
        ],
    );

    expect(app(MailerSendAdapter::class)->verify($request))
        ->toBeInstanceOf(VerifiedWebhook::class);
});

it('requires a signing secret before verifying webhooks', function () {
    config()->set('mail-notifications.providers.mailersend.signing_secret');
    $request = new WebhookRequest(
        provider: 'mailersend',
        body: '{}',
        headers: ['Signature' => str_repeat('a', 64)],
    );

    expect(fn () => app(MailerSendAdapter::class)->verify($request))
        ->toThrow(MailTrackingException::class, 'signing secret');
});

it('authenticates and acknowledges MailerSend URL validation requests', function () {
    $body = json_encode([
        'type' => 'webhook.test',
        'created_at' => '2026-07-30T07:50:00Z',
        'data' => ['id' => 'validation-event'],
    ], JSON_THROW_ON_ERROR);
    $request = new WebhookRequest(
        provider: 'mailersend',
        body: $body,
        headers: [
            'Signature' => hash_hmac(
                'sha256',
                $body,
                'test_Am3L1GuOIc4blLUuHqAPxxwkZaJyEk8G',
            ),
        ],
    );
    $adapter = app(MailerSendAdapter::class);

    $acknowledgement = $adapter->normalize($adapter->verify($request));

    expect($acknowledgement)
        ->toBeInstanceOf(WebhookAcknowledgement::class)
        ->provider->toBe('mailersend')
        ->event->toBe('webhook.test')
        ->reason->toBe('provider_validation');
});

it('never accepts the public validation secret for activity webhooks', function () {
    config()->set(
        'mail-notifications.providers.mailersend.signing_secret',
        'real-activity-secret',
    );
    $body = json_encode(
        mailerSendActivityPayload('delivered'),
        JSON_THROW_ON_ERROR,
    );
    $request = new WebhookRequest(
        provider: 'mailersend',
        body: $body,
        headers: [
            'Signature' => hash_hmac(
                'sha256',
                $body,
                'test_Am3L1GuOIc4blLUuHqAPxxwkZaJyEk8G',
            ),
        ],
    );

    expect(fn () => app(MailerSendAdapter::class)->verify($request))
        ->toThrow(DomainException::class, 'only webhook.test');
});

it('returns typed acknowledgements through the webhook processor', function () {
    Event::fake([MailWebhookAcknowledged::class]);
    config()->set(
        'mail-notifications.extensions.provider_adapters',
        [MailerSendAdapter::class],
    );
    (new MailNotificationsServiceProvider(app()))->register();
    $body = json_encode([
        'type' => 'webhook.test',
        'created_at' => '2026-07-30T08:00:00Z',
    ], JSON_THROW_ON_ERROR);
    $request = new WebhookRequest(
        provider: 'mailersend',
        body: $body,
        headers: [
            'Signature' => hash_hmac(
                'sha256',
                $body,
                'test_Am3L1GuOIc4blLUuHqAPxxwkZaJyEk8G',
            ),
        ],
    );

    $result = app(WebhookProcessor::class)->process('mailersend', $request);

    expect($result)
        ->toBeInstanceOf(WebhookAcknowledgement::class)
        ->event->toBe('webhook.test');
    Event::assertDispatched(
        MailWebhookAcknowledged::class,
        static fn (MailWebhookAcknowledged $event): bool => $event
            ->acknowledgement
            ->event === 'webhook.test',
    );
});

it('retries recent unmatched delivery events to preserve tracking races', function () {
    config()->set([
        'mail-notifications.extensions.provider_adapters' => [
            MailerSendAdapter::class,
        ],
        'mail-notifications.providers.mailersend.signing_secret' => 'webhook-signing-secret',
    ]);
    (new MailNotificationsServiceProvider(app()))->register();
    $body = json_encode([
        'type' => 'activity.delivered',
        'created_at' => CarbonImmutable::now()->subMinute()->toIso8601String(),
        'data' => [
            'id' => 'recent-unmatched-event',
            'message_id' => 'not-tracked',
        ],
    ], JSON_THROW_ON_ERROR);

    expect(fn () => app(WebhookProcessor::class)->process(
        'mailersend',
        new WebhookRequest(
            provider: 'mailersend',
            body: $body,
            headers: [
                'Signature' => hash_hmac('sha256', $body, 'webhook-signing-secret'),
            ],
        ),
    ))->toThrow(
        UnmatchedDeliveryEventException::class,
        'No tracked mail notification',
    );
});

it('acknowledges aged unmatched delivery events from mixed provider domains', function () {
    Event::fake([MailWebhookAcknowledged::class]);
    config()->set([
        'mail-notifications.extensions.provider_adapters' => [
            MailerSendAdapter::class,
        ],
        'mail-notifications.providers.mailersend.signing_secret' => 'webhook-signing-secret',
    ]);
    (new MailNotificationsServiceProvider(app()))->register();
    $body = json_encode([
        'type' => 'activity.delivered',
        'created_at' => CarbonImmutable::now()->subMinutes(10)->toIso8601String(),
        'data' => [
            'id' => 'aged-unmatched-event',
            'message_id' => 'not-tracked',
        ],
    ], JSON_THROW_ON_ERROR);
    $result = app(WebhookProcessor::class)->process(
        'mailersend',
        new WebhookRequest(
            provider: 'mailersend',
            body: $body,
            headers: [
                'Signature' => hash_hmac('sha256', $body, 'webhook-signing-secret'),
            ],
        ),
    );

    expect($result)
        ->toBeInstanceOf(WebhookAcknowledgement::class)
        ->event->toBe('delivered')
        ->reason->toBe('unmatched_event');
    Event::assertDispatched(
        MailWebhookAcknowledged::class,
        static fn (MailWebhookAcknowledged $event): bool => $event
            ->acknowledgement
            ->reason === 'unmatched_event',
    );
});

it('can reject aged unmatched events through the separate strict policy', function () {
    config()->set([
        'mail-notifications.extensions.provider_adapters' => [
            MailerSendAdapter::class,
        ],
        'mail-notifications.providers.mailersend.signing_secret' => 'webhook-signing-secret',
        'mail-notifications.webhooks.unmatched_events.policy' => 'reject',
    ]);
    (new MailNotificationsServiceProvider(app()))->register();
    $body = json_encode([
        'type' => 'activity.delivered',
        'created_at' => CarbonImmutable::now()->subMinutes(10)->toIso8601String(),
        'data' => [
            'id' => 'strict-unmatched-event',
            'message_id' => 'not-tracked',
        ],
    ], JSON_THROW_ON_ERROR);

    expect(fn () => app(WebhookProcessor::class)->process(
        'mailersend',
        new WebhookRequest(
            provider: 'mailersend',
            body: $body,
            headers: [
                'Signature' => hash_hmac('sha256', $body, 'webhook-signing-secret'),
            ],
        ),
    ))->toThrow(UnmatchedDeliveryEventException::class);
});

it('acknowledges typed lifecycle ambiguity without mutation or retry', function () {
    Event::fake([
        MailWebhookAcknowledged::class,
        WebhookEventAmbiguous::class,
    ]);
    config()->set([
        'mail-notifications.extensions.provider_adapters' => [
            MailerSendAdapter::class,
        ],
        'mail-notifications.providers.mailersend.signing_secret' => 'webhook-signing-secret',
        'mail-notifications.webhooks.unmatched_events.policy' => 'reject',
    ]);
    (new MailNotificationsServiceProvider(app()))->register();
    $lifecycle = new AmbiguousTrackingLifecycle;
    app()->instance(TrackingLifecycle::class, $lifecycle);
    app()->forgetInstance(WebhookProcessor::class);
    $body = json_encode([
        'type' => 'activity.delivered',
        'created_at' => CarbonImmutable::now()->toIso8601String(),
        'data' => [
            'id' => 'ambiguous-provider-event',
            'message_id' => 'ambiguous-provider-message',
            'email' => 'private-recipient@example.test',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = app(WebhookProcessor::class)->process(
        'mailersend',
        new WebhookRequest(
            provider: 'mailersend',
            body: $body,
            headers: [
                'Signature' => hash_hmac(
                    'sha256',
                    $body,
                    'webhook-signing-secret',
                ),
            ],
        ),
    );

    expect($result)
        ->toBeInstanceOf(WebhookAcknowledgement::class)
        ->event->toBe('delivered')
        ->reason->toBe('ambiguous_event')
        ->and($lifecycle->applyCalls)->toBe(1)
        ->and(MailNotification::query()->count())->toBe(0)
        ->and(MailNotificationEvent::query()->count())->toBe(0);
    Event::assertDispatched(
        WebhookEventAmbiguous::class,
        static fn (WebhookEventAmbiguous $event): bool => $event->provider
            === 'mailersend'
            && $event->providerEventId === 'ambiguous-provider-event'
            && $event->providerMessageId === 'ambiguous-provider-message'
            && $event->correlationId === null
            && array_keys(get_object_vars($event)) === [
                'provider',
                'providerEventId',
                'providerMessageId',
                'correlationId',
            ],
    );
    Event::assertDispatched(
        MailWebhookAcknowledged::class,
        static fn (MailWebhookAcknowledged $event): bool => $event
            ->acknowledgement
            ->reason === 'ambiguous_event',
    );
});

it('normalizes supported MailerSend activity types', function (
    string $event,
    MailDeliveryStatus $status,
) {
    $normalized = app(MailerSendAdapter::class)->normalize(
        new VerifiedWebhook('mailersend', mailerSendActivityPayload($event)),
    );

    expect($normalized->status)->toBe($status)
        ->and($normalized->eventId)->toBe('event-'.$event)
        ->and($normalized->providerMessageId)->toBe('message-123')
        ->and($normalized->occurredAt->timezoneName)->toBe('UTC');
})->with([
    'sent' => ['sent', MailDeliveryStatus::Accepted],
    'activity sent' => ['activity.sent', MailDeliveryStatus::Accepted],
    'delivered' => ['delivered', MailDeliveryStatus::Delivered],
    'deferred' => ['deferred', MailDeliveryStatus::Delayed],
    'opened' => ['opened', MailDeliveryStatus::Opened],
    'opened unique' => ['opened_unique', MailDeliveryStatus::Opened],
    'clicked' => ['clicked', MailDeliveryStatus::Clicked],
    'clicked unique' => ['clicked_unique', MailDeliveryStatus::Clicked],
    'soft bounced' => ['soft_bounced', MailDeliveryStatus::Delayed],
    'hard bounced' => ['hard_bounced', MailDeliveryStatus::Bounced],
    'spam complaint' => ['spam_complaint', MailDeliveryStatus::Complained],
    'complaint alias' => ['complaint', MailDeliveryStatus::Complained],
    'unsubscribed' => ['unsubscribed', MailDeliveryStatus::Unsubscribed],
    'unsubscribe alias' => ['unsubscribe', MailDeliveryStatus::Unsubscribed],
]);

it('keeps only bounded safe metadata from MailerSend v2 payloads', function () {
    $payload = mailerSendActivityPayload('clicked_unique');
    $payload['data'] = [
        ...$payload['data'],
        'domain_id' => 'domain-123',
        'url' => 'https://example.test/private/path?token=very-secret',
        'ip' => '192.0.2.1',
        'user_agent' => 'Secret Browser',
        'tags' => ['internal-campaign'],
        'meta' => ['private_token' => 'never-store-this'],
        'subject' => 'Private subject',
    ];

    $normalized = app(MailerSendAdapter::class)->normalize(
        new VerifiedWebhook('mailersend', $payload),
    );
    $encodedMetadata = json_encode($normalized->metadata, JSON_THROW_ON_ERROR);

    expect($normalized->metadata)->toBe([
        'event' => 'clicked_unique',
        'recipient' => 'recipient@example.test',
        'email_id' => 'email-123',
        'domain_id' => 'domain-123',
        'unique' => true,
        'link_host' => 'example.test',
    ])->and($encodedMetadata)
        ->not->toContain('private/path')
        ->not->toContain('very-secret')
        ->not->toContain('192.0.2.1')
        ->not->toContain('Secret Browser')
        ->not->toContain('Private subject')
        ->not->toContain('internal-campaign')
        ->not->toContain('never-store-this');
});

it('uses the v2 event identifier and converts occurrence time to UTC', function () {
    $normalized = app(MailerSendAdapter::class)->normalize(
        new VerifiedWebhook(
            'mailersend',
            mailerSendActivityPayload('delivered'),
        ),
    );

    expect($normalized->eventId)->toBe('event-delivered')
        ->and($normalized->occurredAt)->toEqual(
            CarbonImmutable::parse('2026-07-30T07:20:30Z'),
        );
});

it('generates a deterministic fingerprint when the provider event ID is absent', function () {
    $firstPayload = [
        'type' => 'delivered',
        'created_at' => '2026-07-30T07:50:00Z',
        'data' => [
            'message_id' => 'message-123',
            'email' => 'recipient@example.test',
        ],
    ];
    $secondPayload = [
        'data' => [
            'email' => 'recipient@example.test',
            'message_id' => 'message-123',
        ],
        'created_at' => '2026-07-30T07:50:00Z',
        'type' => 'delivered',
    ];
    $adapter = app(MailerSendAdapter::class);

    $first = $adapter->normalize(new VerifiedWebhook('mailersend', $firstPayload));
    $second = $adapter->normalize(new VerifiedWebhook('mailersend', $secondPayload));

    expect($first->eventId)
        ->toBe($second->eventId)
        ->toStartWith('mailersend:');
});

it('accepts correlation metadata when a provider message identifier is absent', function () {
    $payload = [
        'type' => 'delivered',
        'created_at' => CarbonImmutable::now()->subMinute()->timestamp,
        'data' => [
            'id' => 'event-correlation',
            'meta' => [
                'X-Nvl-Mail-Tracking-Id' => '018f8ae0-5dc0-7b42-a44e-33ea19c0f0f2',
            ],
        ],
    ];

    $normalized = app(MailerSendAdapter::class)->normalize(
        new VerifiedWebhook('mailersend', $payload),
    );

    expect($normalized->providerMessageId)->toBeNull()
        ->and($normalized->correlationId)
        ->toBe('018f8ae0-5dc0-7b42-a44e-33ea19c0f0f2');
});

it('normalizes compatible legacy activity payload shapes', function () {
    $normalized = app(MailerSendAdapter::class)->normalize(
        new VerifiedWebhook('mailersend', [
            'event' => 'activity.soft_bounced',
            'timestamp' => CarbonImmutable::now()->subMinute()->timestamp,
            'message_id' => '<legacy-message@example.mailersend.net>',
            'reason' => 'Mailbox temporarily unavailable',
        ]),
    );

    expect($normalized->status)->toBe(MailDeliveryStatus::Delayed)
        ->and($normalized->providerMessageId)->toBe('legacy-message')
        ->and($normalized->metadata)->toMatchArray([
            'event' => 'soft_bounced',
            'bounce_type' => 'soft_bounced',
            'bounce_reason' => 'Mailbox temporarily unavailable',
        ]);
});

it('acknowledges authenticated unsupported events by default', function () {
    $acknowledgement = app(MailerSendAdapter::class)->normalize(
        new VerifiedWebhook('mailersend', [
            'type' => 'future.provider_event',
            'created_at' => '2026-07-30T08:00:00Z',
        ]),
    );

    expect($acknowledgement)
        ->toBeInstanceOf(WebhookAcknowledgement::class)
        ->event->toBe('future.provider_event')
        ->reason->toBe('unsupported_event');
});

it('can reject authenticated unsupported events through strict policy', function () {
    config()->set(
        'mail-notifications.webhooks.unknown_event_policy',
        'reject',
    );

    expect(fn () => app(MailerSendAdapter::class)->normalize(
        new VerifiedWebhook('mailersend', [
            'type' => 'future.provider_event',
            'created_at' => '2026-07-30T08:00:00Z',
        ]),
    ))->toThrow(DomainException::class, 'not supported');
});

it('always acknowledges webhook.test under strict unknown event policy', function () {
    config()->set(
        'mail-notifications.webhooks.unknown_event_policy',
        'reject',
    );

    expect(app(MailerSendAdapter::class)->normalize(
        new VerifiedWebhook('mailersend', [
            'type' => 'webhook.test',
            'created_at' => '2026-07-30T08:00:00Z',
        ]),
    ))->toBeInstanceOf(WebhookAcknowledgement::class);
});

it('rejects missing timestamps and missing delivery identity', function (
    array $payload,
    string $message,
) {
    expect(fn () => app(MailerSendAdapter::class)->normalize(
        new VerifiedWebhook('mailersend', $payload),
    ))->toThrow(DomainException::class, $message);
})->with([
    'missing timestamp' => [
        [
            'type' => 'delivered',
            'data' => ['message_id' => 'message-123'],
        ],
        'timestamp is missing',
    ],
    'missing identity' => [
        [
            'type' => 'delivered',
            'created_at' => '2026-07-30T07:50:00Z',
            'data' => [],
        ],
        'message or correlation identifier',
    ],
]);

it('rejects malformed, stale, and implausibly future provider timestamps', function (
    mixed $createdAt,
    string $message,
) {
    expect(fn () => app(MailerSendAdapter::class)->normalize(
        new VerifiedWebhook('mailersend', [
            'type' => 'delivered',
            'created_at' => $createdAt,
            'data' => ['message_id' => 'message-123'],
        ]),
    ))->toThrow(DomainException::class, $message);
})->with([
    'malformed' => ['not-a-timestamp', 'timestamp is invalid'],
    'stale' => ['2026-07-20T08:00:00Z', 'older than'],
    'future' => ['2026-07-30T08:06:00Z', 'future skew'],
]);

it('accepts JSON content types with parameters for real POST requests', function () {
    config()->set(
        'mail-notifications.extensions.provider_adapters',
        [MailerSendAdapter::class],
    );
    (new MailNotificationsServiceProvider(app()))->register();
    $body = json_encode([
        'type' => 'webhook.test',
        'created_at' => '2026-07-30T08:00:00Z',
    ], JSON_THROW_ON_ERROR);
    $request = new WebhookRequest(
        provider: 'mailersend',
        body: $body,
        headers: [
            'Content-Type' => 'application/json; charset=utf-8',
            'Signature' => hash_hmac(
                'sha256',
                $body,
                'test_Am3L1GuOIc4blLUuHqAPxxwkZaJyEk8G',
            ),
        ],
        method: 'POST',
        uri: '/webhooks/mailersend',
    );

    expect(app(WebhookProcessor::class)->process('mailersend', $request))
        ->toBeInstanceOf(WebhookAcknowledgement::class);
});

it('rejects missing content types and non-POST HTTP methods', function (
    string $method,
    array $headers,
    string $message,
) {
    config()->set(
        'mail-notifications.extensions.provider_adapters',
        [MailerSendAdapter::class],
    );
    (new MailNotificationsServiceProvider(app()))->register();

    expect(fn () => app(WebhookProcessor::class)->process(
        'mailersend',
        new WebhookRequest(
            provider: 'mailersend',
            body: '{}',
            headers: $headers,
            method: $method,
            uri: '/webhooks/mailersend',
        ),
    ))->toThrow(DomainException::class, $message);
})->with([
    'missing content type' => ['POST', [], 'Content-Type'],
    'non-POST method' => ['GET', ['Content-Type' => 'application/json'], 'POST method'],
]);

it('reports registered MailerSend webhook configuration errors through the doctor', function () {
    config()->set(
        'mail-notifications.extensions.provider_adapters',
        [MailerSendAdapter::class],
    );
    config()->set('mail-notifications.providers.mailersend.signing_secret');
    (new MailNotificationsServiceProvider(app()))->register();

    $configuration = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration');

    expect($configuration)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('signing secret');

    $this->artisan('nvl:mail-notifications:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertFailed();
});

it('permits outbound-only MailerSend use without a signing secret', function () {
    config()->set('mail-notifications.webhooks.enabled', false);

    expect(fn () => app(MailerSendAdapter::class)->validateConfiguration(false))
        ->not->toThrow(Throwable::class);
});

it('reports invalid unmatched-event policy and grace through the doctor', function (
    string $key,
    mixed $value,
    string $message,
) {
    config()->set('mail-notifications.webhooks.unmatched_events.'.$key, $value);

    $configuration = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration');

    expect($configuration)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain($message);
})->with([
    'policy' => ['policy', 'drop', 'unmatched webhook event policy'],
    'grace' => ['retry_grace_seconds', 5, 'between 30 and 3600'],
]);

it('fails closed for invalid MailerSend adapter configuration', function (
    string $key,
    mixed $value,
    string $message,
) {
    config()->set(
        'mail-notifications.providers.mailersend.signing_secret',
        'real-activity-secret',
    );
    config()->set('mail-notifications.providers.mailersend.'.$key, $value);

    expect(fn () => app(MailerSendAdapter::class)->validateConfiguration(true))
        ->toThrow(MailTrackingException::class, $message);
})->with([
    'short signing secret' => [
        'signing_secret',
        'too-short',
        'between 16 and 4096 bytes',
    ],
    'oversized signing secret' => [
        'signing_secret',
        str_repeat('s', 4_097),
        'between 16 and 4096 bytes',
    ],
    'mailer names' => ['mailers', 'mailersend', '[mailers]'],
    'message ID headers' => ['message_id_headers', [], '[message_id_headers]'],
    'signature headers' => ['signature_headers', [42], '[signature_headers]'],
    'validation secret type' => [
        'validation_secret',
        [],
        'fixed URL-validation secret',
    ],
    'validation secret mismatch' => [
        'validation_secret',
        'different-validation-secret-value',
        'fixed URL-validation secret',
    ],
    'past timestamp bound' => [
        'timestamp_bounds.maximum_past_age_seconds',
        60,
        'timestamp bound',
    ],
    'future timestamp bound' => [
        'timestamp_bounds.maximum_future_skew_seconds',
        '300',
        'timestamp bound',
    ],
]);
