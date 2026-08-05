<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces persistence of one new scheduled message.
 */
final class ScheduledMailScheduled
{
    use Dispatchable;

    /**
     * Create the scheduled event.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $factoryAlias,
        public readonly int $payloadVersion,
        public readonly CarbonImmutable $scheduledFor,
        public readonly CarbonImmutable $availableAt,
    ) {}
}
