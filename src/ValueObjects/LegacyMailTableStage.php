<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * One forward-only legacy table rename performed before package migrations.
 */
final readonly class LegacyMailTableStage
{
    public function __construct(
        public string $sourceTable,
        public string $stagingTable,
    ) {}
}
