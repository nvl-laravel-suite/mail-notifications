<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\ScheduledMailMessage;

/**
 * Privacy-bounded list projection of one scheduled message.
 */
final readonly class ScheduledMailListData
{
    /**
     * Columns safe to load for the list projection.
     *
     * The encrypted TO envelope is read only to derive one primary recipient.
     * It is never retained or serialized by this value object.
     *
     * @var list<string>
     */
    public const array COLUMNS = [
        'id',
        'factory_alias',
        'payload_version',
        'to_recipients',
        'status',
        'scheduled_for',
        'available_at',
        'attempts',
        'max_attempts',
        'sent_at',
        'failed_at',
        'cancelled_at',
        'created_at',
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
        public ?CarbonImmutable $sentAt,
        public ?CarbonImmutable $failedAt,
        public ?CarbonImmutable $cancelledAt,
        public CarbonImmutable $createdAt,
    ) {}

    public static function fromModel(ScheduledMailMessage $message): self
    {
        return new self(
            id: $message->id,
            factoryAlias: $message->factory_alias,
            payloadVersion: $message->payload_version,
            primaryRecipient: self::primaryRecipient($message),
            status: $message->status,
            scheduledFor: $message->scheduled_for,
            availableAt: $message->available_at,
            attempts: $message->attempts,
            maxAttempts: $message->max_attempts,
            sentAt: $message->sent_at,
            failedAt: $message->failed_at,
            cancelledAt: $message->cancelled_at,
            createdAt: $message->created_at,
        );
    }

    /**
     * Return the deliberately minimal display recipient from the protected TO envelope.
     */
    public static function primaryRecipient(ScheduledMailMessage $message): ?Recipient
    {
        $recipients = $message->getAttribute('to_recipients');

        if (! is_array($recipients) || $recipients === []) {
            return null;
        }

        $primary = $recipients[0] ?? null;

        if (! is_array($primary)
            || ! isset($primary['email'])
            || ! is_string($primary['email'])) {
            throw new InvalidArgumentException(
                'Persisted scheduled mail primary recipient is invalid.',
            );
        }

        $name = $primary['name'] ?? null;

        if ($name !== null && ! is_string($name)) {
            throw new InvalidArgumentException(
                'Persisted scheduled mail primary recipient name is invalid.',
            );
        }

        return new Recipient($primary['email'], $name);
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
            'sent_at' => $this->sentAt?->toISOString(),
            'failed_at' => $this->failedAt?->toISOString(),
            'cancelled_at' => $this->cancelledAt?->toISOString(),
            'created_at' => $this->createdAt->toISOString(),
        ];
    }
}
