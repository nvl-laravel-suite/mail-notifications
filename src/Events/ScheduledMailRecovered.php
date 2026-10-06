<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces recovery of one expired scheduled-mail claim.
 *
 * @api
 */
final class ScheduledMailRecovered implements DomainEvent
{
    /**
     * Create the recovered event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly int $attempt,
        public readonly bool $willRetry,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
