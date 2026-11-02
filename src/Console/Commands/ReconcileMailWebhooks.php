<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use Nvl\MailNotifications\Services\Delivery\WebhookProcessor;

/** Trusted operator entry point for the opt-in managed delivery engine.
 * @internal
 */
class ReconcileMailWebhooks extends Command
{
    protected $signature = 'nvl:mail-notifications:delivery:reconcile-webhooks {--limit=100} {--provider-account=}';

    protected $description = 'Reprocess verified webhook receipts that are awaiting exact message correlation';

    /**
     * Reconcile bounded retained provider observations against local attempts.
     */
    public function handle(WebhookProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 1000.');

            return self::FAILURE;
        }
        $account = $this->option('provider-account');
        if ($account !== null && ! is_string($account)) {
            $this->error('Provider account must be a string.');

            return self::FAILURE;
        }
        $this->info('Reconciled '.$processor->reconcile($limit, $account).' webhook receipts.');

        return self::SUCCESS;
    }
}
