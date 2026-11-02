<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nvl\MailNotifications\Services\Integrations\SyncProviderConfiguration;
use Throwable;

/**
 * Processes one persisted provider page checkpoint, with safe retry and stale-checkpoint rejection.
 */
class ProcessProviderSyncPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public string $runId, public int $checkpoint) {}

    /**
     * Process this persisted page checkpoint; duplicate or stale checkpoints must not advance another worker's run.
     */
    public function handle(SyncProviderConfiguration $synchronizer): void
    {
        $synchronizer->process($this->runId, $this->checkpoint);
    }

    /**
     * Persist terminal queue failure for this checkpoint without overwriting a newer synchronization claim.
     */
    public function failed(?Throwable $exception): void
    {
        app(SyncProviderConfiguration::class)->failed($this->runId, $this->checkpoint);
    }
}
