<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\ValueObjects\ScheduledMailReadQuery;
use Nvl\MailNotifications\ValueObjects\ScheduledMailStatistics;

/**
 * Defines the consumer-facing GetScheduledMailStatisticsAction workflow.
 *
 * @api
 */
interface GetScheduledMailStatisticsContract
{
    /**
     * Execute the execute workflow.
     */
    public function execute(
        Authenticatable $actor,
        ScheduledMailReadQuery $filters,
    ): ScheduledMailStatistics;
}
