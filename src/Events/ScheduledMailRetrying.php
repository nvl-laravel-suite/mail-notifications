<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Carbon\CarbonImmutable;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces a deterministic retry after a failed or recovered attempt.
 *
 * @api
 */
final class ScheduledMailRetrying implements DomainEvent
{
    /**
     * Create the retrying event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly int $attempt,
        public readonly CarbonImmutable $availableAt,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
