<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Carbon\CarbonImmutable;
use DomainException;
use JsonException;
use Nvl\MailNotifications\Contracts\ProviderAdapter;
use Nvl\MailNotifications\Contracts\ProviderMessageIdResolver;
use Nvl\MailNotifications\Contracts\WebhookEventNormalizer;
use Nvl\MailNotifications\Contracts\WebhookSignatureVerifier;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\TransportResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\MailNotifications\ValueObjects\VerifiedWebhook;
use Nvl\MailNotifications\ValueObjects\WebhookRequest;

/**
 * Exercises the complete configuration-first provider adapter contract.
 */
final class PluggedProviderAdapter implements ProviderAdapter, ProviderMessageIdResolver, WebhookEventNormalizer, WebhookSignatureVerifier
{
    /**
     * Return the stable fixture provider name.
     */
    public function name(): string
    {
        return 'plugged-provider';
    }

    /**
     * Determine whether the fixture owns this mailer.
     */
    public function supports(TransportResult $result): bool
    {
        return $result->mailer === 'plugged-provider';
    }

    /**
     * Resolve the provider message identifier from Laravel transport acceptance.
     */
    public function resolve(TransportResult $result): ProviderMessageId
    {
        return new ProviderMessageId(
            provider: $this->name(),
            value: $result->message->getMessageId(),
        );
    }

    /**
     * Verify the fixture webhook signature and decode its JSON payload.
     *
     * @throws JsonException
     */
    public function verify(WebhookRequest $request): VerifiedWebhook
    {
        $signature = $request->headers['x-plugged-signature'] ?? '';

        if (! hash_equals('valid-signature', $signature)) {
            throw new DomainException('The fixture webhook signature is invalid.');
        }

        $payload = json_decode(
            $request->body,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($payload) || array_is_list($payload)) {
            throw new DomainException('The fixture webhook payload must be an object.');
        }

        $normalizedPayload = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw new DomainException(
                    'The fixture webhook payload requires string keys.',
                );
            }

            $normalizedPayload[$key] = $value;
        }

        return new VerifiedWebhook(
            provider: $this->name(),
            payload: $normalizedPayload,
        );
    }

    /**
     * Normalize the verified fixture webhook into one delivered event.
     */
    public function normalize(VerifiedWebhook $webhook): VerifiedDeliveryEvent
    {
        $eventId = $webhook->payload['event_id'] ?? null;
        $messageId = $webhook->payload['message_id'] ?? null;

        if (! is_string($eventId) || ! is_string($messageId)) {
            throw new DomainException(
                'The fixture webhook requires event and message identifiers.',
            );
        }

        return new VerifiedDeliveryEvent(
            provider: $this->name(),
            eventId: $eventId,
            status: MailDeliveryStatus::Delivered,
            occurredAt: CarbonImmutable::now('UTC'),
            providerMessageId: $messageId,
        );
    }
}
