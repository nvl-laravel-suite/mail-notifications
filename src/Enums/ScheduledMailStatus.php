<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/**
 * Describes the durable state of one scheduled mail message.
 */
enum ScheduledMailStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Determine whether no further scheduler transition is allowed.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Sent,
            self::Failed,
            self::Cancelled,
        ], true);
    }
}
