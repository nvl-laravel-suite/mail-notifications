<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces successful scheduled delivery finalization.
 */
final class ScheduledMailSent
{
    use Dispatchable;

    /**
     * Create the sent event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly int $attempt,
    ) {}
}
