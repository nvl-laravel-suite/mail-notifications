<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement;

/**
 * Announces an authenticated webhook acknowledged without lifecycle mutation.
 */
final class MailWebhookAcknowledged
{
    use Dispatchable;

    /**
     * Create the provider webhook acknowledgement event.
     */
    public function __construct(
        public readonly WebhookAcknowledgement $acknowledgement,
    ) {}
}
