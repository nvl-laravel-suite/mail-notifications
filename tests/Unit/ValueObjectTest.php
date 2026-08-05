<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\ValueObjects\NotifiableReference;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\MailNotifications\ValueObjects\WebhookRequest;

it('requires verified provider events to carry valid identity', function (
    string $provider,
    string $eventId,
    ?string $providerMessageId,
    ?string $correlationId,
) {
    expect(fn () => new VerifiedDeliveryEvent(
        provider: $provider,
        eventId: $eventId,
        status: MailDeliveryStatus::Delivered,
        occurredAt: CarbonImmutable::now(),
        providerMessageId: $providerMessageId,
        correlationId: $correlationId,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'empty provider' => ['', 'event-1', 'message-1', null],
    'empty event identifier' => ['provider', '', 'message-1', null],
    'missing correlation identifiers' => ['provider', 'event-1', null, null],
    'blank provider message identifier' => ['provider', 'event-1', ' ', null],
    'blank correlation identifier' => ['provider', 'event-1', null, ' '],
    'invalid correlation identifier' => ['provider', 'event-1', null, 'not-a-uuid'],
]);

it('normalizes public tracking identifiers', function () {
    $event = new VerifiedDeliveryEvent(
        provider: ' provider ',
        eventId: ' event-1 ',
        status: MailDeliveryStatus::Delivered,
        occurredAt: CarbonImmutable::now(),
        providerMessageId: ' message-1 ',
    );
    $messageId = new ProviderMessageId(' provider ', ' message-1 ');
    $context = TrackingContext::forCategory(' receipt ');
    $notifiable = new NotifiableReference(' account ', ' account-1 ');

    expect($event)
        ->provider->toBe('provider')
        ->eventId->toBe('event-1')
        ->providerMessageId->toBe('message-1')
        ->and($messageId)
        ->provider->toBe('provider')
        ->value->toBe('message-1')
        ->and($context->category)->toBe('receipt')
        ->and($notifiable)
        ->type->toBe('account')
        ->identifier->toBe('account-1');
});

it('preserves exact internationalized recipient identities accepted by Symfony', function () {
    $caseSensitive = new Recipient(' User.Name@Example.COM ', ' Recipient ');
    $internationalized = new Recipient('δοκιμή@παράδειγμα.δοκιμή');

    expect($caseSensitive)
        ->email->toBe('User.Name@Example.COM')
        ->name->toBe('Recipient')
        ->and($internationalized->email)
        ->toBe('δοκιμή@παράδειγμα.δοκιμή');
});

it('rejects recipients that Symfony cannot deliver', function () {
    expect(fn () => new Recipient('not-an-email'))
        ->toThrow(
            InvalidArgumentException::class,
            'A tracked recipient must contain a valid email address.',
        );
});

it('rejects tracking identifiers that exceed storage limits', function (
    Closure $createValue,
) {
    expect($createValue)->toThrow(InvalidArgumentException::class);
})->with([
    'provider name' => [
        fn () => new ProviderMessageId(str_repeat('p', 129), 'message'),
    ],
    'provider message identifier' => [
        fn () => new ProviderMessageId('provider', str_repeat('m', 256)),
    ],
    'message category' => [
        fn () => TrackingContext::forCategory(str_repeat('c', 129)),
    ],
    'notifiable type' => [
        fn () => new NotifiableReference(str_repeat('t', 129), 'identifier'),
    ],
    'notifiable identifier' => [
        fn () => new NotifiableReference('type', str_repeat('i', 129)),
    ],
    'provider event identifier' => [
        fn () => new VerifiedDeliveryEvent(
            provider: 'provider',
            eventId: str_repeat('e', 256),
            status: MailDeliveryStatus::Delivered,
            occurredAt: CarbonImmutable::now(),
            providerMessageId: 'message',
        ),
    ],
]);

it('normalizes provider webhook inputs from Laravel requests', function () {
    $request = Request::create(
        uri: '/webhooks/mail?attempt=1',
        method: 'POST',
        server: ['HTTP_X_PROVIDER_SIGNATURE' => ' signed '],
        content: '{"event":"delivered"}',
    );

    $webhook = WebhookRequest::fromLaravelRequest(' provider ', $request);

    expect($webhook)
        ->provider->toBe('provider')
        ->body->toBe('{"event":"delivered"}')
        ->headers->toHaveKey('x-provider-signature', 'signed')
        ->method->toBe('POST')
        ->uri->toBe('/webhooks/mail?attempt=1');
});

it('rejects invalid provider webhook names', function (string $provider) {
    expect(fn () => new WebhookRequest($provider, '{}', []))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'blank provider' => ' ',
    'provider exceeding storage limit' => str_repeat('p', 129),
]);
