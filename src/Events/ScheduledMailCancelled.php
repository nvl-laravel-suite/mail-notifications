<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces cancellation of one pending scheduled message.
 */
final class ScheduledMailCancelled
{
    use Dispatchable;

    /**
     * Create the cancelled event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly CarbonImmutable $cancelledAt,
    ) {}
}
