<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Carbon\CarbonImmutable;
use DomainException;
use Nvl\MailNotifications\Contracts\ProviderAdapter;
use Nvl\MailNotifications\Contracts\WebhookEventNormalizer;
use Nvl\MailNotifications\Contracts\WebhookSignatureVerifier;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\MailNotifications\ValueObjects\VerifiedWebhook;
use Nvl\MailNotifications\ValueObjects\WebhookRequest;

/** Verifies complete wire bytes and preserves the provider's stable replay identity. */
final readonly class OwnershipProvider implements ProviderAdapter, WebhookEventNormalizer, WebhookSignatureVerifier
{
    public function name(): string
    {
        return 'native-provider';
    }

    public function verify(WebhookRequest $request): VerifiedWebhook
    {
        if (! hash_equals(hash_hmac('sha256', $request->body, 'native-test-secret'), $request->headers['x-signature'] ?? '')) {
            throw new DomainException('Invalid native provider signature.');
        }

        $payload = json_decode($request->body, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || array_is_list($payload)) {
            throw new DomainException('Provider payload requires a JSON object.');
        }
        $normalized = [];
        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw new DomainException('Provider fields require names.');
            }
            $normalized[$key] = $value;
        }

        return new VerifiedWebhook($this->name(), $normalized);
    }

    public function normalize(VerifiedWebhook $webhook): VerifiedDeliveryEvent
    {
        $id = $webhook->payload['event_id'] ?? null;
        $message = $webhook->payload['message_id'] ?? null;
        $occurred = $webhook->payload['occurred_at'] ?? null;
        if (! is_string($id) || ! is_string($message) || ! is_string($occurred)) {
            throw new DomainException('Provider identities and event time require strings.');
        }

        return new VerifiedDeliveryEvent($this->name(), $id, MailDeliveryStatus::Delivered,
            CarbonImmutable::parse($occurred), providerMessageId: $message);
    }
}
