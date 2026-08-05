<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces a deterministic retry after a failed or recovered attempt.
 */
final class ScheduledMailRetrying
{
    use Dispatchable;

    /**
     * Create the retrying event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly int $attempt,
        public readonly CarbonImmutable $availableAt,
    ) {}
}
