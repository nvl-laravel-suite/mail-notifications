<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces that a provider-neutral tracking attempt was persisted.
 *
 * @api
 */
final class MailTrackingStarted implements DomainEvent
{
    /**
     * Create the tracking-started event.
     *
     * @param  array<string, string|int|bool|null>  $correlation
     */
    public function __construct(
        public readonly TrackingAttempt $attempt,
        public readonly string $category,
        public readonly array $correlation = [],
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
