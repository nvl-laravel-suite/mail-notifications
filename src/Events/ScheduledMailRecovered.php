<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces recovery of one expired scheduled-mail claim.
 */
final class ScheduledMailRecovered
{
    use Dispatchable;

    /**
     * Create the recovered event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly int $attempt,
        public readonly bool $willRetry,
    ) {}
}
