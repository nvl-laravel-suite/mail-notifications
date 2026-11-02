<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use Nvl\MailNotifications\Services\Delivery\ClientWebhookPublisher;

/** Trusted operator entry point for the opt-in managed delivery engine.
 * @internal
 */
class RecoverClientWebhooks extends Command
{
    protected $signature = 'nvl:mail-notifications:delivery:recover-client-webhooks {--limit=100}';

    protected $description = 'Queue due client webhook outbox deliveries and recover expired leases';

    /**
     * Recover due signed callback work and expired dispatch leases.
     */
    public function handle(ClientWebhookPublisher $publisher): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 1000.');

            return self::FAILURE;
        }
        $this->info('Queued '.$publisher->recover($limit).' client webhook deliveries.');

        return self::SUCCESS;
    }
}
