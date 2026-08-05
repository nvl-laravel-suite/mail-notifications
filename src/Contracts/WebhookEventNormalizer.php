<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\MailNotifications\ValueObjects\VerifiedWebhook;
use Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement;

/**
 * Converts one authenticated provider webhook into a core delivery event.
 */
interface WebhookEventNormalizer
{
    /**
     * Normalize a verified provider payload for the core lifecycle.
     */
    public function normalize(
        VerifiedWebhook $webhook,
    ): VerifiedDeliveryEvent|WebhookAcknowledgement;
}
