<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Nvl\MailNotifications\Enums\MailDeliveryStatus;

/**
 * Describes the idempotent outcome of one provider lifecycle event.
 */
final readonly class TransitionResult
{
    /**
     * Create a transition result.
     */
    public function __construct(
        public string $notificationId,
        public MailDeliveryStatus $previousStatus,
        public MailDeliveryStatus $currentStatus,
        public bool $applied,
        public bool $duplicate = false,
    ) {}
}
