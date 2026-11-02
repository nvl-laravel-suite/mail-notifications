<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use Nvl\MailNotifications\Services\MailHistoryPruner;

/** Trusted operator entry point for the opt-in managed delivery engine.
 * @internal
 */
class PruneMailHistory extends Command
{
    protected $signature = 'nvl:mail-notifications:delivery:prune {--days=365 : Minimum retention, at least 30 days} {--dry-run : Count without deleting}';

    protected $description = 'Drain bounded batches of old terminal mail history and unused archived assets within the maintenance time budget';

    /**
     * Prune eligible terminal delivery history under the requested bounded retention policy.
     */
    public function handle(MailHistoryPruner $pruner): int
    {
        $value = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 30, 'max_range' => 36500]]);
        if ($value === false) {
            $this->error('Retention must be between 30 and 36500 days.');

            return self::FAILURE;
        }
        $counts = $pruner->prune((int) $value, (bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? 'Eligible' : 'Pruned').': '.$counts['messages'].' messages, '.$counts['receipts'].' webhook receipts, '.$counts['assets'].' assets.');

        return self::SUCCESS;
    }
}
