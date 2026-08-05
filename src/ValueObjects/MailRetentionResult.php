<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Describes one deterministic retention preview or completed prune run.
 */
final readonly class MailRetentionResult
{
    /**
     * Create the retention result.
     */
    public function __construct(
        public CarbonImmutable $notificationCutoff,
        public ?CarbonImmutable $scheduledMessageCutoff,
        public int $notificationCount,
        public int $providerEventCount,
        public int $scheduledMessageCount,
        public bool $dryRun,
    ) {}
}
