<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use Nvl\MailNotifications\Services\Integrations\SyncProviderConfiguration;

/** Trusted operator entry point for the opt-in managed delivery engine.
 * @internal
 */
class RecoverProviderSyncs extends Command
{
    protected $signature = 'nvl:mail-notifications:delivery:recover-provider-syncs {--limit=100}';

    protected $description = 'Recover due provider synchronization pages and expired worker leases';

    /**
     * Recover due and expired provider page checkpoints in bounded work.
     */
    public function handle(SyncProviderConfiguration $synchronizer): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 1000.');

            return self::FAILURE;
        }
        $this->info('Dispatched '.$synchronizer->recover($limit).' provider synchronization pages.');

        return self::SUCCESS;
    }
}
