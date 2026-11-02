<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use Nvl\MailNotifications\Services\Delivery\AttachmentRetention;

/** Trusted operator entry point for the opt-in managed delivery engine.
 * @internal
 */
class PruneMessageAttachments extends Command
{
    protected $signature = 'nvl:mail-notifications:delivery:prune-attachments {--dry-run : Count without deleting attachment bytes}';

    protected $description = 'Drain expired attachment bytes within the maintenance time budget while preserving metadata';

    /**
     * Expire retained attachment bytes without removing durable message evidence.
     */
    public function handle(AttachmentRetention $retention): int
    {
        $count = $retention->prune((bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? 'Eligible' : 'Expired').': '.$count.' message attachments.');

        return self::SUCCESS;
    }
}
