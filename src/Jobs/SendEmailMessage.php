<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nvl\MailNotifications\Services\Delivery\MessageSender;

/**
 * Submits an accepted message by identifier through the guarded delivery state machine.
 */
class SendEmailMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $messageId) {}

    /**
     * Hand the persisted message identifier to the delivery guard; do not rebuild host content in the worker.
     */
    public function handle(MessageSender $sender): void
    {
        $sender->send($this->messageId);
    }
}
