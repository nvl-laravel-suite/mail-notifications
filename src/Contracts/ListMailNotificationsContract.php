<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadPage;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;

/**
 * Defines the consumer-facing ListMailNotificationsAction workflow.
 *
 * @api
 */
interface ListMailNotificationsContract
{
    /**
     * Execute the execute workflow.
     */
    public function execute(
        Authenticatable $actor,
        MailNotificationReadQuery $filters,
    ): MailNotificationReadPage;
}
