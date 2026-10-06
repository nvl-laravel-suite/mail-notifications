<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadData;

/**
 * Defines the consumer-facing ShowMailNotificationAction workflow.
 *
 * @api
 */
interface ShowMailNotificationContract
{
    /**
     * Execute the execute workflow.
     */
    public function execute(
        Authenticatable $actor,
        string $id,
    ): MailNotificationReadData;
}
