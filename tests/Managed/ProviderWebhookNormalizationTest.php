<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Exceptions\ManagedProviderWebhookRejected;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Services\Delivery\ProviderWebhookAdapter;

function rawProviderWebhookAccount(string $provider = 'mailersend'): ProviderAccount
{
    return new ProviderAccount(['provider' => $provider, 'webhook_secret' => 'private-secret', 'credentials' => []]);
}

it('verifies raw MailerSend bytes with case-insensitive headers without an HTTP request', function (): void {
    $body = json_encode(['type' => 'activity.delivered', 'created_at' => now()->toIso8601String(), 'data' => ['id' => 'event-1', 'message_id' => 'message-1', 'recipient' => 'reader@example.com']], JSON_THROW_ON_ERROR);
    $headers = ['sIgNaTuRe' => hash_hmac('sha256', $body, 'private-secret')];
    $events = app(ProviderWebhookAdapter::class)->normalize(rawProviderWebhookAccount(), $body, 'application/json; charset=utf-8', $headers);

    expect($events)->toHaveCount(1);
    expect($events[0]['key'])->toBe(hash('sha256', $body));
    expect($events[0]['payload']['type'])->toBe('activity.delivered');
    expect($events[0]['payload']['data']['message_id'])->toBe('message-1');
    expect(fn () => app(ProviderWebhookAdapter::class)->normalize(rawProviderWebhookAccount(), $body.' ', 'json', $headers))->toThrow(ManagedProviderWebhookRejected::class);
});

it('keeps the public MailerSend test key limited to acknowledgement-only probes', function (): void {
    $testKey = 'test_Am3L1GuOIc4blLUuHqAPxxwkZaJyEk8G';
    $body = '{"type":"webhook.test"}';
    expect(app(ProviderWebhookAdapter::class)->normalize(rawProviderWebhookAccount(), $body, 'json', ['signature' => hash_hmac('sha256', $body, $testKey)]))->toBe([]);
    $event = '{"type":"activity.delivered","data":{"id":"forged"}}';
    expect(fn () => app(ProviderWebhookAdapter::class)->normalize(rawProviderWebhookAccount(), $event, 'json', ['signature' => hash_hmac('sha256', $event, $testKey)]))->toThrow(ManagedProviderWebhookRejected::class);
});

it('verifies raw SendGrid signature and timestamp inputs', function (): void {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($key === false) {
        throw new RuntimeException('The test signing key could not be created.');
    }
    $details = openssl_pkey_get_details($key);
    $account = rawProviderWebhookAccount('sendgrid');
    $account->webhook_public_key = $details['key'];
    $body = json_encode([['email' => 'reader@example.com', 'timestamp' => now()->timestamp, 'event' => 'delivered', 'sg_event_id' => 'sg-event', 'sg_message_id' => 'sg-message']], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    openssl_sign($timestamp.$body, $signature, $key, OPENSSL_ALGO_SHA256);
    $headers = ['X-Twilio-Email-Event-Webhook-Timestamp' => $timestamp, 'X-Twilio-Email-Event-Webhook-Signature' => base64_encode($signature)];

    $event = app(ProviderWebhookAdapter::class)->normalize($account, $body, 'application/json', $headers)[0];
    expect($event['payload']['type'])->toBe('activity.delivered');
    expect($event['payload']['data']['id'])->toBe('sg:sg-event');
    $stale = (string) now()->subDays(4)->timestamp;
    openssl_sign($stale.$body, $staleSignature, $key, OPENSSL_ALGO_SHA256);
    expect(fn () => app(ProviderWebhookAdapter::class)->normalize($account, $body, 'json', ['x-twilio-email-event-webhook-timestamp' => $stale, 'x-twilio-email-event-webhook-signature' => base64_encode($staleSignature)]))->toThrow(ManagedProviderWebhookRejected::class);
});

it('verifies raw Mailgun signatures and preserves normalized event identifiers', function (): void {
    $timestamp = (string) now()->timestamp;
    $token = str_repeat('a', 50);
    $body = json_encode(['signature' => ['timestamp' => $timestamp, 'token' => $token, 'signature' => hash_hmac('sha256', $timestamp.$token, 'private-secret')], 'event-data' => ['event' => 'delivered', 'id' => 'mg-event', 'timestamp' => now()->timestamp, 'recipient' => 'reader@example.com', 'message' => ['headers' => ['message-id' => 'mg-message']]]], JSON_THROW_ON_ERROR);
    $event = app(ProviderWebhookAdapter::class)->normalize(rawProviderWebhookAccount('mailgun'), $body, 'json', [])[0];

    expect($event['payload']['data']['message_id'])->toBe('<mg-message>');
    expect($event['key'])->toBe(hash('sha256', 'mailgun:'.$timestamp.':'.$token));
});

it('returns transport-independent rejection reasons for bounded raw inputs', function (string $provider, string $body, ?string $contentType, array $headers, string $reason): void {
    try {
        app(ProviderWebhookAdapter::class)->normalize(rawProviderWebhookAccount($provider), $body, $contentType, $headers);
        $this->fail('The webhook should be rejected.');
    } catch (ManagedProviderWebhookRejected $exception) {
        expect($exception->reason)->toBe($reason);
        expect($exception->getCode())->toBe(0);
    }
})->with([
    ['smtp', '{}', 'json', [], 'unsupported_provider'],
    ['mailersend', str_repeat('x', 262145), 'json', [], 'payload_too_large'],
    ['mailersend', '{}', 'text/plain', [], 'unsupported_content_type'],
    ['mailersend', '{}', null, [], 'unsupported_content_type'],
    ['mailersend', '{}', 'json', [], 'invalid_signature'],
    ['mailersend', '[', 'json', [], 'invalid_payload'],
    ['mailersend', 'true', 'json', [], 'invalid_payload'],
    ['mailersend', '{}', 'json', ['signature' => ['invalid']], 'invalid_payload'],
]);

it('retains native validation errors for authenticated malformed provider fields', function (): void {
    $body = '{"type":"activity.delivered","created_at":"not-a-date","data":{"id":"event-1"}}';

    expect(fn () => app(ProviderWebhookAdapter::class)->normalize(rawProviderWebhookAccount(), $body, 'json', ['signature' => hash_hmac('sha256', $body, 'private-secret')]))->toThrow(ValidationException::class);
});

it('rejects unknown domain rejection reasons instead of creating unmappable outcomes', function (): void {
    expect(fn () => new ManagedProviderWebhookRejected('unknown_reason', 'Rejected.'))->toThrow(InvalidArgumentException::class);
});
