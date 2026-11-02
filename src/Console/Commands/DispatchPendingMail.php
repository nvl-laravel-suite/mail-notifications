<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use Nvl\MailNotifications\Services\Delivery\MessageDispatcher;

/** Trusted operator entry point for the opt-in managed delivery engine.
 * @internal
 */
class DispatchPendingMail extends Command
{
    protected $signature = 'nvl:mail-notifications:delivery:dispatch-pending {--limit=100}';

    protected $aliases = ['nvl:mail-notifications:delivery:process-scheduled'];

    protected $description = 'Dispatch due scheduled messages and recover durable pending send intents';

    /**
     * Dispatch due accepted intents and recover abandoned submission claims.
     */
    public function handle(MessageDispatcher $dispatcher): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 1000.');

            return self::FAILURE;
        }
        $this->info('Dispatched '.$dispatcher->dispatchPending($limit).' messages.');

        return self::SUCCESS;
    }
}
