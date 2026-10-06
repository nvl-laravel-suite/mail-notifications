<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\ValueObjects\ScheduledMailDetailData;

/**
 * Defines the consumer-facing ShowScheduledMailMessageAction workflow.
 *
 * @api
 */
interface ShowScheduledMailMessageContract
{
    /**
     * Execute the execute workflow.
     */
    public function execute(
        Authenticatable $actor,
        string $id,
    ): ScheduledMailDetailData;
}
