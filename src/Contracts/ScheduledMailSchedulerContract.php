<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;

/**
 * Defines the consumer boundary for scheduling and updating pending mail.
 *
 * @api
 */
interface ScheduledMailSchedulerContract
{
    /**
     * Persist one validated message for future delivery.
     */
    public function schedule(ScheduleMailData $data): ScheduledMailMessage;

    /**
     * Cancel one message that has not been claimed.
     */
    public function cancel(string $messageId): ScheduledMailMessage;

    /**
     * Move one unclaimed message to a new delivery and submission schedule.
     */
    public function reschedule(
        string $messageId,
        CarbonImmutable $scheduledFor,
        ?CarbonImmutable $availableAt = null,
    ): ScheduledMailMessage;

    /**
     * Atomically replace every host-owned field on one pending message.
     */
    public function replacePending(
        string $messageId,
        ScheduleMailData $data,
    ): ScheduledMailMessage;
}
