<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;

/**
 * Announces successful transport or provider acceptance.
 */
final class MailAcceptedByProvider
{
    use Dispatchable;

    /**
     * Create the provider-accepted event.
     */
    public function __construct(
        public readonly TrackingAttempt $attempt,
        public readonly ProviderMessageId $messageId,
    ) {}
}
