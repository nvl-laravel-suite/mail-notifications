<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/**
 * Enumerates provider-neutral outbound delivery and engagement states.
 *
 * @api
 */
enum MailDeliveryStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Delayed = 'delayed';
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Unsubscribed = 'unsubscribed';

    /**
     * Statuses from least to most severe outcome.
     *
     * One row summarizes every recipient of a message, so it keeps the most
     * severe status any provider event reported, in whichever order they arrive.
     */
    private const array SEVERITY = [
        self::Pending,
        self::Accepted,
        self::Delayed,
        self::Delivered,
        self::Opened,
        self::Clicked,
        self::Unsubscribed,
        self::Complained,
        self::Bounced,
        self::Rejected,
        self::Failed,
    ];

    /**
     * Determine whether the status may advance to the supplied state.
     *
     * A local transport failure is only possible before provider acceptance.
     */
    public function canTransitionTo(self $next): bool
    {
        if ($next === self::Failed) {
            return $this === self::Pending;
        }

        return array_search($next, self::SEVERITY, true)
            > array_search($this, self::SEVERITY, true);
    }

    /**
     * Determine whether the status is a final outcome rather than delivery progress.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Bounced,
            self::Complained,
            self::Rejected,
            self::Failed,
            self::Unsubscribed,
        ], true);
    }
}
