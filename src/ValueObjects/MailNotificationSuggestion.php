<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;

/**
 * Minimal authorized autocomplete projection.
 */
final readonly class MailNotificationSuggestion
{
    public function __construct(
        public string $id,
        public ?string $subject,
        public ?string $primaryRecipientEmail,
        public MailDeliveryStatus $status,
        public CarbonImmutable $createdAt,
    ) {}

    public static function fromModel(MailNotification $notification): self
    {
        return new self(
            id: $notification->id,
            subject: $notification->subject,
            primaryRecipientEmail: $notification->primary_recipient_email,
            status: $notification->status,
            createdAt: $notification->created_at,
        );
    }

    /**
     * @return array{id: string, subject: string|null, primary_recipient_email: string|null, status: string, created_at: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'primary_recipient_email' => $this->primaryRecipientEmail,
            'status' => $this->status->value,
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }
}
