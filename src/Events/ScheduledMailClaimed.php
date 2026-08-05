<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces that one due message was fenced for a delivery attempt.
 */
final class ScheduledMailClaimed
{
    use Dispatchable;

    /**
     * Create the claimed event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly int $attempt,
    ) {}
}
