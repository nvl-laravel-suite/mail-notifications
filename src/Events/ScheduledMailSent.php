<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces successful scheduled delivery finalization.
 *
 * @api
 */
final class ScheduledMailSent implements DomainEvent
{
    /**
     * Create the sent event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly int $attempt,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
