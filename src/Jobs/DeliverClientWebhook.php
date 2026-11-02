<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nvl\MailNotifications\Services\Delivery\ClientWebhookSender;

/**
 * Executes a persisted signed client callback intent rather than rebuilding its payload.
 */
class DeliverClientWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public string $deliveryId) {}

    /**
     * Execute the stored callback intent using the sender's claim, target and retry safeguards.
     */
    public function handle(ClientWebhookSender $sender): void
    {
        $sender->send($this->deliveryId);
    }
}
