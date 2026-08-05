<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces a verified webhook that ambiguously matched tracked deliveries.
 */
final class WebhookEventAmbiguous
{
    use Dispatchable;

    /**
     * Create the privacy-safe webhook ambiguity event.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $providerEventId,
        public readonly ?string $providerMessageId,
        public readonly ?string $correlationId,
    ) {}
}
