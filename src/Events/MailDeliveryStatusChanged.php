<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;

/**
 * Announces one monotonic provider-neutral lifecycle transition.
 */
final class MailDeliveryStatusChanged
{
    use Dispatchable;

    /**
     * Create the delivery status changed event.
     */
    public function __construct(
        public readonly string $notificationId,
        public readonly MailDeliveryStatus $previousStatus,
        public readonly MailDeliveryStatus $currentStatus,
    ) {}
}
