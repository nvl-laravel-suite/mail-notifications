<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces one monotonic provider-neutral lifecycle transition.
 *
 * @api
 */
final class MailDeliveryStatusChanged implements DomainEvent
{
    /**
     * Create the delivery status changed event.
     */
    public function __construct(
        public readonly string $notificationId,
        public readonly MailDeliveryStatus $previousStatus,
        public readonly MailDeliveryStatus $currentStatus,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
