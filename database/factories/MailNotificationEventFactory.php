<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;

/**
 * @extends Factory<MailNotificationEvent>
 */
final class MailNotificationEventFactory extends Factory
{
    protected $model = MailNotificationEvent::class;

    /**
     * @return array<model-property<MailNotificationEvent>, mixed>
     */
    public function definition(): array
    {
        return [
            'mail_notification_id' => MailNotification::factory()->delivered(),
            'provider' => 'factory-provider',
            'provider_event_id' => 'factory-'.Str::uuid(),
            'provider_message_id' => null,
            'normalized_type' => MailDeliveryStatus::Delivered,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'metadata' => [
                'source' => 'factory',
            ],
            'processed_at' => CarbonImmutable::now('UTC'),
        ];
    }

    /**
     * Represent a pending provider event.
     */
    public function pending(): static
    {
        return $this->normalizedAs(MailDeliveryStatus::Pending);
    }

    /**
     * Represent an accepted provider event.
     */
    public function accepted(): static
    {
        return $this->normalizedAs(MailDeliveryStatus::Accepted);
    }

    /**
     * Represent a delayed provider event.
     */
    public function delayed(): static
    {
        return $this->normalizedAs(MailDeliveryStatus::Delayed);
    }

    /**
     * Represent a delivered provider event.
     */
    public function delivered(): static
    {
        return $this->normalizedAs(MailDeliveryStatus::Delivered);
    }

    /**
     * Represent an opened provider event.
     */
    public function opened(): static
    {
        return $this->normalizedAs(MailDeliveryStatus::Opened);
    }

    /**
     * Represent a clicked provider event.
     */
    public function clicked(): static
    {
        return $this->normalizedAs(MailDeliveryStatus::Clicked);
    }

    /**
     * Represent a bounced provider event.
     */
    public function bounced(): static
    {
        return $this->normalizedAs(MailDeliveryStatus::Bounced);
    }

    /**
     * Represent a complaint provider event.
     */
    public function complained(): static
    {
        return $this->normalizedAs(MailDeliveryStatus::Complained);
    }

    /**
     * Represent a rejected provider event.
     */
    public function rejected(): static
    {
        return $this->normalizedAs(MailDeliveryStatus::Rejected);
    }

    /**
     * Represent a failed provider event.
     */
    public function failed(): static
    {
        return $this->normalizedAs(MailDeliveryStatus::Failed);
    }

    /**
     * Represent an unsubscribe provider event.
     */
    public function unsubscribed(): static
    {
        return $this->normalizedAs(MailDeliveryStatus::Unsubscribed);
    }

    /**
     * Set any provider-neutral normalized event type.
     */
    public function normalizedAs(MailDeliveryStatus $status): static
    {
        return $this->state(function () use ($status): array {
            $occurredAt = CarbonImmutable::now('UTC');

            return [
                'normalized_type' => $status,
                'occurred_at' => $occurredAt,
                'processed_at' => $occurredAt,
            ];
        });
    }
}
