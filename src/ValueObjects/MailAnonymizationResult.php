<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Describes one deterministic anonymization preview or completed stage run.
 */
final readonly class MailAnonymizationResult
{
    /**
     * Create the anonymization result.
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
