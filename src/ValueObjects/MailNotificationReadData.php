<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;

/**
 * Privacy-bounded projection of one tracked delivery attempt.
 */
final readonly class MailNotificationReadData
{
    /**
     * Columns safe to load into an administrative projection.
     *
     * @var list<string>
     */
    public const array COLUMNS = [
        'id',
        'correlation_id',
        'queue_reference',
        'mailer',
        'provider',
        'provider_message_id',
        'status',
        'message_category',
        'subject',
        'from_email',
        'from_name',
        'primary_recipient_email',
        'notifiable_type',
        'notifiable_id',
        'accepted_at',
        'delivered_at',
        'failed_at',
        'status_changed_at',
        'provider_occurred_at',
        'redacted_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @param  list<MailNotificationEventReadData>  $events
     */
    public function __construct(
        public string $id,
        public string $correlationId,
        public ?string $queueReference,
        public string $mailer,
        public ?string $provider,
        public ?string $providerMessageId,
        public MailDeliveryStatus $status,
        public string $messageCategory,
        public ?string $subject,
        public ?string $fromEmail,
        public ?string $fromName,
        public ?string $primaryRecipientEmail,
        public ?string $notifiableType,
        public ?string $notifiableId,
        public ?CarbonImmutable $acceptedAt,
        public ?CarbonImmutable $deliveredAt,
        public ?CarbonImmutable $failedAt,
        public ?CarbonImmutable $statusChangedAt,
        public ?CarbonImmutable $providerOccurredAt,
        public ?CarbonImmutable $redactedAt,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
        public array $events = [],
    ) {}

    public static function fromModel(MailNotification $notification): self
    {
        $events = $notification->relationLoaded('providerEvents')
            ? array_values($notification->providerEvents
                ->map(static fn (MailNotificationEvent $event): MailNotificationEventReadData => MailNotificationEventReadData::fromModel($event))
                ->values()
                ->all())
            : [];

        return new self(
            id: $notification->id,
            correlationId: $notification->correlation_id,
            queueReference: $notification->queue_reference,
            mailer: $notification->mailer,
            provider: $notification->provider,
            providerMessageId: $notification->provider_message_id,
            status: $notification->status,
            messageCategory: $notification->message_category,
            subject: $notification->subject,
            fromEmail: $notification->from_email,
            fromName: $notification->from_name,
            primaryRecipientEmail: $notification->primary_recipient_email,
            notifiableType: $notification->notifiable_type,
            notifiableId: $notification->notifiable_id,
            acceptedAt: $notification->accepted_at,
            deliveredAt: $notification->delivered_at,
            failedAt: $notification->failed_at,
            statusChangedAt: $notification->status_changed_at,
            providerOccurredAt: $notification->provider_occurred_at,
            redactedAt: $notification->redacted_at,
            createdAt: $notification->created_at,
            updatedAt: $notification->updated_at,
            events: $events,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'correlation_id' => $this->correlationId,
            'queue_reference' => $this->queueReference,
            'mailer' => $this->mailer,
            'provider' => $this->provider,
            'provider_message_id' => $this->providerMessageId,
            'status' => $this->status->value,
            'message_category' => $this->messageCategory,
            'subject' => $this->subject,
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'primary_recipient_email' => $this->primaryRecipientEmail,
            'notifiable_type' => $this->notifiableType,
            'notifiable_id' => $this->notifiableId,
            'accepted_at' => $this->acceptedAt?->toISOString(),
            'delivered_at' => $this->deliveredAt?->toISOString(),
            'failed_at' => $this->failedAt?->toISOString(),
            'status_changed_at' => $this->statusChangedAt?->toISOString(),
            'provider_occurred_at' => $this->providerOccurredAt?->toISOString(),
            'redacted_at' => $this->redactedAt?->toISOString(),
            'created_at' => $this->createdAt->toISOString(),
            'updated_at' => $this->updatedAt->toISOString(),
            'events' => array_map(
                static fn (MailNotificationEventReadData $event): array => $event->toArray(),
                $this->events,
            ),
        ];
    }
}
