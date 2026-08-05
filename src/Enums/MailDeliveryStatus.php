<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/**
 * Enumerates provider-neutral outbound delivery and engagement states.
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
     * Determine whether the status may advance to the supplied state.
     */
    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return false;
        }

        return match ($this) {
            self::Pending => in_array($next, [
                self::Accepted,
                self::Delayed,
                self::Delivered,
                self::Opened,
                self::Clicked,
                self::Bounced,
                self::Complained,
                self::Rejected,
                self::Failed,
                self::Unsubscribed,
            ], true),
            self::Accepted => in_array($next, [
                self::Delayed,
                self::Delivered,
                self::Opened,
                self::Clicked,
                self::Bounced,
                self::Complained,
                self::Rejected,
                self::Unsubscribed,
            ], true),
            self::Delayed => in_array($next, [
                self::Delivered,
                self::Opened,
                self::Clicked,
                self::Bounced,
                self::Complained,
                self::Rejected,
                self::Unsubscribed,
            ], true),
            self::Delivered => in_array($next, [
                self::Opened,
                self::Clicked,
                self::Complained,
                self::Unsubscribed,
            ], true),
            self::Opened => in_array($next, [
                self::Clicked,
                self::Complained,
                self::Unsubscribed,
            ], true),
            self::Clicked => in_array($next, [
                self::Complained,
                self::Unsubscribed,
            ], true),
            self::Bounced,
            self::Complained,
            self::Rejected,
            self::Failed,
            self::Unsubscribed => false,
        };
    }

    /**
     * Determine whether no later delivery state may replace this status.
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
