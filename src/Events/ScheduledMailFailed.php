<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces terminal scheduled-delivery failure without exception messages.
 *
 * @api
 */
final class ScheduledMailFailed implements DomainEvent
{
    /**
     * Create the failed event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly int $attempt,
        public readonly string $failureType,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
