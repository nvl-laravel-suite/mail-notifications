<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces terminal scheduled-delivery failure without exception messages.
 */
final class ScheduledMailFailed
{
    use Dispatchable;

    /**
     * Create the failed event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly int $attempt,
        public readonly string $failureType,
    ) {}
}
