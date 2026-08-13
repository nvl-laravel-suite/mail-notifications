<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\ScheduledMailMessage;

/**
 * Privacy-bounded detail projection of one scheduled message.
 */
final readonly class ScheduledMailDetailData
{
    /** @var list<string> */
    public const array COLUMNS = [
        ...ScheduledMailListData::COLUMNS,
        'last_attempt_at',
        'notifiable_type',
        'notifiable_id',
        'redacted_at',
        'updated_at',
    ];

    public function __construct(
        public string $id,
        public string $factoryAlias,
        public int $payloadVersion,
        public ?Recipient $primaryRecipient,
        public ScheduledMailStatus $status,
        public CarbonImmutable $scheduledFor,
        public CarbonImmutable $availableAt,
        public int $attempts,
        public int $maxAttempts,
        public ?CarbonImmutable $lastAttemptAt,
        public ?string $notifiableType,
        public ?string $notifiableId,
        public ?CarbonImmutable $sentAt,
        public ?CarbonImmutable $failedAt,
        public ?CarbonImmutable $cancelledAt,
        public ?CarbonImmutable $redactedAt,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
    ) {}

    public static function fromModel(ScheduledMailMessage $message): self
    {
        return new self(
            id: $message->id,
            factoryAlias: $message->factory_alias,
            payloadVersion: $message->payload_version,
            primaryRecipient: ScheduledMailListData::primaryRecipient($message),
            status: $message->status,
            scheduledFor: $message->scheduled_for,
            availableAt: $message->available_at,
            attempts: $message->attempts,
            maxAttempts: $message->max_attempts,
            lastAttemptAt: $message->last_attempt_at,
            notifiableType: $message->notifiable_type,
            notifiableId: $message->notifiable_id,
            sentAt: $message->sent_at,
            failedAt: $message->failed_at,
            cancelledAt: $message->cancelled_at,
            redactedAt: $message->redacted_at,
            createdAt: $message->created_at,
            updatedAt: $message->updated_at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'factory_alias' => $this->factoryAlias,
            'payload_version' => $this->payloadVersion,
            'primary_recipient' => $this->primaryRecipient?->toArray(),
            'status' => $this->status->value,
            'scheduled_for' => $this->scheduledFor->toISOString(),
            'available_at' => $this->availableAt->toISOString(),
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'last_attempt_at' => $this->lastAttemptAt?->toISOString(),
            'notifiable_type' => $this->notifiableType,
            'notifiable_id' => $this->notifiableId,
            'sent_at' => $this->sentAt?->toISOString(),
            'failed_at' => $this->failedAt?->toISOString(),
            'cancelled_at' => $this->cancelledAt?->toISOString(),
            'redacted_at' => $this->redactedAt?->toISOString(),
            'created_at' => $this->createdAt->toISOString(),
            'updated_at' => $this->updatedAt->toISOString(),
        ];
    }
}
