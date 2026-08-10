<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotificationEvent;

/**
 * Metadata-free provider event projection for authorized administrators.
 */
final readonly class MailNotificationEventReadData
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $providerEventId,
        public ?string $providerMessageId,
        public MailDeliveryStatus $type,
        public CarbonImmutable $occurredAt,
        public CarbonImmutable $processedAt,
        public ?CarbonImmutable $redactedAt,
    ) {}

    public static function fromModel(MailNotificationEvent $event): self
    {
        return new self(
            id: $event->id,
            provider: $event->provider,
            providerEventId: $event->provider_event_id,
            providerMessageId: $event->provider_message_id,
            type: $event->normalized_type,
            occurredAt: $event->occurred_at,
            processedAt: $event->processed_at,
            redactedAt: $event->redacted_at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'provider_event_id' => $this->providerEventId,
            'provider_message_id' => $this->providerMessageId,
            'type' => $this->type->value,
            'occurred_at' => $this->occurredAt->toISOString(),
            'processed_at' => $this->processedAt->toISOString(),
            'redacted_at' => $this->redactedAt?->toISOString(),
        ];
    }
}
