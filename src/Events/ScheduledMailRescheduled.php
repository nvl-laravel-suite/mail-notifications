<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Carbon\CarbonImmutable;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces a pending message's availability-time change.
 *
 * @api
 */
final class ScheduledMailRescheduled implements DomainEvent
{
    /**
     * Create the rescheduled event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly CarbonImmutable $previousScheduledFor,
        public readonly CarbonImmutable $previousAvailableAt,
        public readonly CarbonImmutable $scheduledFor,
        public readonly CarbonImmutable $availableAt,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
