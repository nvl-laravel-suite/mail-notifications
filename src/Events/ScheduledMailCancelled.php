<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Carbon\CarbonImmutable;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces cancellation of one pending scheduled message.
 *
 * @api
 */
final class ScheduledMailCancelled implements DomainEvent
{
    /**
     * Create the cancelled event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly CarbonImmutable $cancelledAt,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
