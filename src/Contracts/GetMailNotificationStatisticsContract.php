<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;
use Nvl\MailNotifications\ValueObjects\MailNotificationStatistics;

/**
 * Defines the consumer-facing GetMailNotificationStatisticsAction workflow.
 *
 * @api
 */
interface GetMailNotificationStatisticsContract
{
    /**
     * Execute the execute workflow.
     */
    public function execute(
        Authenticatable $actor,
        MailNotificationReadQuery $filters,
    ): MailNotificationStatistics;
}
