<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces successful transport or provider acceptance.
 *
 * @api
 */
final class MailAcceptedByProvider implements DomainEvent
{
    /**
     * Create the provider-accepted event.
     */
    public function __construct(
        public readonly TrackingAttempt $attempt,
        public readonly ProviderMessageId $messageId,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
