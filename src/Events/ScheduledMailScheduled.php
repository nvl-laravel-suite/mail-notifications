<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Carbon\CarbonImmutable;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces persistence of one new scheduled message.
 *
 * @api
 */
final class ScheduledMailScheduled implements DomainEvent
{
    /**
     * Create the scheduled event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $factoryAlias,
        public readonly int $payloadVersion,
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
