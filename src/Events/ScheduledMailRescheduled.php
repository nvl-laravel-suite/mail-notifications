<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces a pending message's availability-time change.
 */
final class ScheduledMailRescheduled
{
    use Dispatchable;

    /**
     * Create the rescheduled event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly CarbonImmutable $previousScheduledFor,
        public readonly CarbonImmutable $previousAvailableAt,
        public readonly CarbonImmutable $scheduledFor,
        public readonly CarbonImmutable $availableAt,
    ) {}
}
