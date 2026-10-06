<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces an operational tracking failure without carrying message content.
 *
 * @api
 */
final class MailTrackingFailed implements DomainEvent
{
    /**
     * Create the tracking failure event.
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly ?string $attemptId,
        public readonly string $exceptionClass,
        public readonly ?ProviderMessageId $messageId = null,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
