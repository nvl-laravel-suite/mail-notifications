<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\ValueObjects\ScheduledMailReadPage;
use Nvl\MailNotifications\ValueObjects\ScheduledMailReadQuery;

/**
 * Defines the consumer-facing ListScheduledMailMessagesAction workflow.
 *
 * @api
 */
interface ListScheduledMailMessagesContract
{
    /**
     * Execute the execute workflow.
     */
    public function execute(
        Authenticatable $actor,
        ScheduledMailReadQuery $filters,
    ): ScheduledMailReadPage;
}
