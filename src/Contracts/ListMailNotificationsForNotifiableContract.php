<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadPage;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;
use Nvl\MailNotifications\ValueObjects\NotifiableReference;

/**
 * Defines the consumer-facing ListMailNotificationsForNotifiableAction workflow.
 *
 * @api
 */
interface ListMailNotificationsForNotifiableContract
{
    /**
     * Execute the execute workflow.
     */
    public function execute(
        Authenticatable $actor,
        NotifiableReference $notifiable,
        ?MailNotificationReadQuery $filters = null,
    ): MailNotificationReadPage;
}
