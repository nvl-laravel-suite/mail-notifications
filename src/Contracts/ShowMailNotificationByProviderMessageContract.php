<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadData;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;

/**
 * Defines the consumer-facing ShowMailNotificationByProviderMessageAction workflow.
 *
 * @api
 */
interface ShowMailNotificationByProviderMessageContract
{
    /**
     * Execute the execute workflow.
     */
    public function execute(
        Authenticatable $actor,
        ProviderMessageId $messageId,
    ): MailNotificationReadData;
}
