<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Carries explicit remote webhook removal scope and dry-run options.
 */
final readonly class RemoteWebhookRemoveOptions
{
    /**
     * Create removal options.
     */
    public function __construct(
        public bool $all = false,
        public bool $dryRun = false,
    ) {}
}
