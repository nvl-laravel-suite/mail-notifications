<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use RuntimeException;

/**
 * @extends Factory<ScheduledMailMessage>
 */
final class ScheduledMailMessageFactory extends Factory
{
    protected $model = ScheduledMailMessage::class;

    /**
     * @return array<model-property<ScheduledMailMessage>, mixed>
     */
    public function definition(): array
    {
        $scheduledFor = CarbonImmutable::now('UTC')->addHour();

        return [
            'factory_alias' => 'factory.message',
            'payload_version' => 1,
            'payload' => [
                'fixture_reference' => 'mail-notifications-factory',
            ],
            'to_recipients' => [
                [
                    'email' => 'recipient@example.test',
                    'name' => 'Example Recipient',
                ],
            ],
            'cc_recipients' => null,
            'bcc_recipients' => null,
            'status' => ScheduledMailStatus::Pending,
            'scheduled_for' => $scheduledFor,
            'available_at' => $scheduledFor,
            'attempts' => 0,
            'max_attempts' => 3,
            'last_attempt_at' => null,
            'claim_token' => null,
            'locked_until' => null,
            'last_error' => null,
            'notifiable_type' => null,
            'notifiable_id' => null,
            'metadata' => [
                'source' => 'factory',
            ],
            'sent_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
        ];
    }

    /**
     * Represent a future message waiting to become available.
     */
    public function pending(): static
    {
        return $this->state(function (): array {
            $scheduledFor = CarbonImmutable::now('UTC')->addHour();

            return [
                'status' => ScheduledMailStatus::Pending,
                'scheduled_for' => $scheduledFor,
                'available_at' => $scheduledFor,
                'attempts' => 0,
                'last_attempt_at' => null,
                'claim_token' => null,
                'locked_until' => null,
                'last_error' => null,
                'sent_at' => null,
                'failed_at' => null,
                'cancelled_at' => null,
            ];
        });
    }

    /**
     * Represent a pending message available for immediate claiming.
     */
    public function due(): static
    {
        return $this->state(function (): array {
            $availableAt = CarbonImmutable::now('UTC');

            return [
                'status' => ScheduledMailStatus::Pending,
                'scheduled_for' => $availableAt,
                'available_at' => $availableAt,
                'attempts' => 0,
                'last_attempt_at' => null,
                'claim_token' => null,
                'locked_until' => null,
                'last_error' => null,
                'sent_at' => null,
                'failed_at' => null,
                'cancelled_at' => null,
            ];
        });
    }

    /**
     * Represent a message held by an active processing claim.
     */
    public function processing(): static
    {
        return $this->state(function (): array {
            $claimedAt = CarbonImmutable::now('UTC');
            $availableAt = $claimedAt->subMinutes(5);

            return [
                'status' => ScheduledMailStatus::Processing,
                'scheduled_for' => $availableAt,
                'available_at' => $availableAt,
                'attempts' => 1,
                'last_attempt_at' => $claimedAt,
                'claim_token' => (string) Str::uuid(),
                'locked_until' => $claimedAt->addMinutes(5),
                'last_error' => null,
                'sent_at' => null,
                'failed_at' => null,
                'cancelled_at' => null,
            ];
        });
    }

    /**
     * Represent a retryable failure waiting for its next attempt.
     */
    public function retrying(): static
    {
        return $this->state(function (): array {
            $failedAt = CarbonImmutable::now('UTC');

            return [
                'status' => ScheduledMailStatus::Pending,
                'scheduled_for' => $failedAt->subMinutes(5),
                'available_at' => $failedAt->addMinute(),
                'attempts' => 1,
                'last_attempt_at' => $failedAt,
                'claim_token' => null,
                'locked_until' => null,
                'last_error' => RuntimeException::class,
                'sent_at' => null,
                'failed_at' => null,
                'cancelled_at' => null,
            ];
        });
    }

    /**
     * Represent a successfully sent scheduled message.
     */
    public function sent(): static
    {
        return $this->state(function (): array {
            $sentAt = CarbonImmutable::now('UTC');
            $availableAt = $sentAt->subMinutes(5);

            return [
                'status' => ScheduledMailStatus::Sent,
                'scheduled_for' => $availableAt,
                'available_at' => $availableAt,
                'attempts' => 1,
                'last_attempt_at' => $sentAt,
                'claim_token' => null,
                'locked_until' => null,
                'last_error' => null,
                'sent_at' => $sentAt,
                'failed_at' => null,
                'cancelled_at' => null,
            ];
        });
    }

    /**
     * Represent a scheduled message that exhausted its attempts.
     */
    public function failed(): static
    {
        return $this->state(function (): array {
            $failedAt = CarbonImmutable::now('UTC');
            $availableAt = $failedAt->subMinutes(5);

            return [
                'status' => ScheduledMailStatus::Failed,
                'scheduled_for' => $availableAt,
                'available_at' => $availableAt,
                'attempts' => 3,
                'max_attempts' => 3,
                'last_attempt_at' => $failedAt,
                'claim_token' => null,
                'locked_until' => null,
                'last_error' => RuntimeException::class,
                'sent_at' => null,
                'failed_at' => $failedAt,
                'cancelled_at' => null,
            ];
        });
    }

    /**
     * Represent a scheduled message cancelled before processing.
     */
    public function cancelled(): static
    {
        return $this->state(function (): array {
            $cancelledAt = CarbonImmutable::now('UTC');
            $scheduledFor = $cancelledAt->addHour();

            return [
                'status' => ScheduledMailStatus::Cancelled,
                'scheduled_for' => $scheduledFor,
                'available_at' => $scheduledFor,
                'attempts' => 0,
                'last_attempt_at' => null,
                'claim_token' => null,
                'locked_until' => null,
                'last_error' => null,
                'sent_at' => null,
                'failed_at' => null,
                'cancelled_at' => $cancelledAt,
            ];
        });
    }
}
