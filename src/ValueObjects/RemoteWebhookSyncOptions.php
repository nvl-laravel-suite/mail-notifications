<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Carries explicit remote webhook synchronization safety options.
 */
final readonly class RemoteWebhookSyncOptions
{
    /**
     * Create synchronization options.
     */
    public function __construct(
        public bool $force = false,
        public bool $dryRun = false,
    ) {}
}
