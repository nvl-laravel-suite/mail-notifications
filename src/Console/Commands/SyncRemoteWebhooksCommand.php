<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Nvl\MailNotifications\Services\RemoteWebhookManagerRegistry;
use Nvl\MailNotifications\ValueObjects\RemoteWebhookSyncOptions;

/**
 * Synchronizes explicitly configured provider webhooks without boot-time I/O.
 */
final class SyncRemoteWebhooksCommand extends RemoteWebhookCommand
{
    protected $signature = 'nvl:mail-notifications:webhooks:sync
        {--provider= : Limit synchronization to one registered provider}
        {--force : Apply updates when an existing webhook differs}
        {--dry-run : Inspect and report changes without remote writes}';

    protected $description = 'Synchronize explicitly configured remote mail webhooks';

    /**
     * Run one bounded remote webhook synchronization pass.
     */
    public function handle(RemoteWebhookManagerRegistry $registry): int
    {
        $managers = $this->selectedManagers($registry);

        if ($managers === false) {
            return self::INVALID;
        }

        $options = new RemoteWebhookSyncOptions(
            force: (bool) $this->option('force'),
            dryRun: (bool) $this->option('dry-run'),
        );
        $succeeded = true;

        foreach ($managers as $manager) {
            $managerSucceeded = $this->executeManager(
                $manager,
                static fn () => $manager->sync($options),
            );
            $succeeded = $managerSucceeded && $succeeded;
        }

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }
}
