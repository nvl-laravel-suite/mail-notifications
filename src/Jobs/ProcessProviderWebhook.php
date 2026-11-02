<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nvl\MailNotifications\Services\Delivery\WebhookProcessor;

/**
 * Normalizes a previously persisted provider receipt outside the inbound HTTP request.
 */
class ProcessProviderWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public string $receiptId) {}

    /**
     * Process the persisted receipt through normalization and reconciliation; transport verification happens before receipt creation.
     */
    public function handle(WebhookProcessor $processor): void
    {
        $processor->process($this->receiptId);
    }
}
