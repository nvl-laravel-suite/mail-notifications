<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Carbon\CarbonImmutable;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces atomic replacement of one pending scheduled message.
 *
 * @api
 */
final class ScheduledMailReplaced implements DomainEvent
{
    /**
     * Create the replaced event without exposing payload or recipients.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $previousFactoryAlias,
        public readonly string $factoryAlias,
        public readonly int $previousPayloadVersion,
        public readonly int $payloadVersion,
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
