<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use Nvl\MailNotifications\Services\MailFilePruner;

/** Trusted operator entry point for the opt-in managed delivery engine.
 * @internal
 */
class PruneOrphanMailFiles extends Command
{
    protected $signature = 'nvl:mail-notifications:delivery:prune-orphan-files {--dry-run : Count without deleting files or staging records}';

    protected $description = 'Remove interrupted mail uploads after their staging grace period';

    /**
     * Prune abandoned file-staging records and unreferenced bytes under the configured grace policy.
     */
    public function handle(MailFilePruner $pruner): int
    {
        $counts = $pruner->prune((bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? 'Would remove ' : 'Removed ').$counts['files'].' orphan files and '.$counts['records'].' staging records.');

        return self::SUCCESS;
    }
}
