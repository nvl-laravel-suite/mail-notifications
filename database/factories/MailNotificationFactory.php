<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;

/**
 * @extends Factory<MailNotification>
 */
final class MailNotificationFactory extends Factory
{
    protected $model = MailNotification::class;

    /**
     * @return array<model-property<MailNotification>, mixed>
     */
    public function definition(): array
    {
        $correlationId = (string) Str::uuid();

        return [
            'id' => $correlationId,
            'correlation_id' => $correlationId,
            'queue_reference' => null,
            'mailer' => 'array',
            'provider' => null,
            'provider_message_id' => null,
            'status' => MailDeliveryStatus::Pending,
            'message_category' => 'factory.message',
            'subject' => null,
            'from_email' => 'sender@example.test',
            'from_name' => 'Example Sender',
            'to_recipients' => [
                [
                    'email' => 'recipient@example.test',
                    'name' => 'Example Recipient',
                ],
            ],
            'cc_recipients' => null,
            'bcc_recipients' => null,
            'primary_recipient_email' => 'recipient@example.test',
            'notifiable_type' => null,
            'notifiable_id' => null,
            'metadata' => [
                'source' => 'factory',
            ],
            'accepted_at' => null,
            'delivered_at' => null,
            'failed_at' => null,
            'status_changed_at' => CarbonImmutable::now('UTC'),
            'provider_occurred_at' => null,
        ];
    }

    /**
     * Represent a delivery waiting for transport acceptance.
     */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'provider' => null,
            'provider_message_id' => null,
            'status' => MailDeliveryStatus::Pending,
            'accepted_at' => null,
            'delivered_at' => null,
            'failed_at' => null,
            'status_changed_at' => CarbonImmutable::now('UTC'),
            'provider_occurred_at' => null,
        ]);
    }

    /**
     * Represent a delivery accepted by its provider.
     */
    public function accepted(): static
    {
        return $this->providerState(
            status: MailDeliveryStatus::Accepted,
            hasAcceptance: true,
        );
    }

    /**
     * Represent a provider-delayed delivery.
     */
    public function delayed(): static
    {
        return $this->providerState(
            status: MailDeliveryStatus::Delayed,
            hasAcceptance: true,
        );
    }

    /**
     * Represent a delivery completed by its provider.
     */
    public function delivered(): static
    {
        return $this->providerState(
            status: MailDeliveryStatus::Delivered,
            hasAcceptance: true,
            hasDelivery: true,
        );
    }

    /**
     * Represent an opened delivery.
     */
    public function opened(): static
    {
        return $this->providerState(
            status: MailDeliveryStatus::Opened,
            hasAcceptance: true,
            hasDelivery: true,
        );
    }

    /**
     * Represent a clicked delivery.
     */
    public function clicked(): static
    {
        return $this->providerState(
            status: MailDeliveryStatus::Clicked,
            hasAcceptance: true,
            hasDelivery: true,
        );
    }

    /**
     * Represent a delivery that bounced after provider acceptance.
     */
    public function bounced(): static
    {
        return $this->providerState(
            status: MailDeliveryStatus::Bounced,
            hasAcceptance: true,
            hasFailure: true,
        );
    }

    /**
     * Represent a delivered message that was reported as spam.
     */
    public function complained(): static
    {
        return $this->providerState(
            status: MailDeliveryStatus::Complained,
            hasAcceptance: true,
            hasDelivery: true,
            hasFailure: true,
        );
    }

    /**
     * Represent a delivery rejected before provider acceptance.
     */
    public function rejected(): static
    {
        return $this->providerState(
            status: MailDeliveryStatus::Rejected,
            hasFailure: true,
        );
    }

    /**
     * Represent a local transport failure without a provider identity.
     */
    public function failed(): static
    {
        return $this->state(function (): array {
            $failedAt = CarbonImmutable::now('UTC');

            return [
                'provider' => null,
                'provider_message_id' => null,
                'status' => MailDeliveryStatus::Failed,
                'accepted_at' => null,
                'delivered_at' => null,
                'failed_at' => $failedAt,
                'status_changed_at' => $failedAt,
                'provider_occurred_at' => null,
            ];
        });
    }

    /**
     * Represent a delivered message whose recipient unsubscribed.
     */
    public function unsubscribed(): static
    {
        return $this->providerState(
            status: MailDeliveryStatus::Unsubscribed,
            hasAcceptance: true,
            hasDelivery: true,
            hasFailure: true,
        );
    }

    /**
     * Build a coherent provider-originated lifecycle state.
     */
    private function providerState(
        MailDeliveryStatus $status,
        bool $hasAcceptance = false,
        bool $hasDelivery = false,
        bool $hasFailure = false,
    ): static {
        return $this->state(function () use (
            $hasAcceptance,
            $hasDelivery,
            $hasFailure,
            $status,
        ): array {
            $occurredAt = CarbonImmutable::now('UTC');
            $acceptedAt = $status === MailDeliveryStatus::Accepted
                ? $occurredAt
                : $occurredAt->subMinutes(5);
            $deliveredAt = $status === MailDeliveryStatus::Delivered
                ? $occurredAt
                : $occurredAt->subMinutes(2);

            return [
                'provider' => 'factory-provider',
                'provider_message_id' => 'factory-'.Str::uuid(),
                'status' => $status,
                'accepted_at' => $hasAcceptance ? $acceptedAt : null,
                'delivered_at' => $hasDelivery ? $deliveredAt : null,
                'failed_at' => $hasFailure ? $occurredAt : null,
                'status_changed_at' => $occurredAt,
                'provider_occurred_at' => $occurredAt,
            ];
        });
    }
}
