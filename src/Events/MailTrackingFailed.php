<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;

/**
 * Announces an operational tracking failure without carrying message content.
 */
final class MailTrackingFailed
{
    use Dispatchable;

    /**
     * Create the tracking failure event.
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly ?string $attemptId,
        public readonly string $exceptionClass,
        public readonly ?ProviderMessageId $messageId = null,
    ) {}
}
