<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Nvl\MailNotifications\Services\RemoteWebhookManagerRegistry;
use Nvl\MailNotifications\ValueObjects\RemoteWebhookRemoveOptions;

/**
 * Removes configured-name or explicitly all provider webhooks.
 */
final class RemoveRemoteWebhooksCommand extends RemoteWebhookCommand
{
    protected $signature = 'nvl:mail-notifications:webhooks:remove
        {--provider= : Limit removal to one registered provider}
        {--all : Remove every webhook in each selected provider domain}
        {--dry-run : Inspect and report removals without remote writes}';

    protected $description = 'Remove explicitly selected remote mail webhooks';

    /**
     * Run one bounded remote webhook removal pass.
     */
    public function handle(RemoteWebhookManagerRegistry $registry): int
    {
        $managers = $this->selectedManagers($registry);

        if ($managers === false) {
            return self::INVALID;
        }

        $options = new RemoteWebhookRemoveOptions(
            all: (bool) $this->option('all'),
            dryRun: (bool) $this->option('dry-run'),
        );
        $succeeded = true;

        foreach ($managers as $manager) {
            $managerSucceeded = $this->executeManager(
                $manager,
                static fn () => $manager->remove($options),
            );
            $succeeded = $managerSucceeded && $succeeded;
        }

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }
}
