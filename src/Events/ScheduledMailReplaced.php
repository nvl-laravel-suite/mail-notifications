<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces atomic replacement of one pending scheduled message.
 */
final class ScheduledMailReplaced
{
    use Dispatchable;

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
    ) {}
}
