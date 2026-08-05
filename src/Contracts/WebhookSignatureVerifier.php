<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Nvl\MailNotifications\ValueObjects\VerifiedWebhook;
use Nvl\MailNotifications\ValueObjects\WebhookRequest;

/**
 * Verifies provider webhook authenticity before normalization.
 */
interface WebhookSignatureVerifier
{
    /**
     * Verify a provider request or reject it without leaking secret material.
     */
    public function verify(WebhookRequest $request): VerifiedWebhook;
}
