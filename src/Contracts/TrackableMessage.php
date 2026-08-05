<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Nvl\MailNotifications\ValueObjects\TrackingContext;

/**
 * Marks a Laravel Mailable as eligible for opt-in delivery tracking.
 */
interface TrackableMessage
{
    /**
     * Return the portable context associated with this delivery.
     */
    public function trackingContext(): TrackingContext;
}
