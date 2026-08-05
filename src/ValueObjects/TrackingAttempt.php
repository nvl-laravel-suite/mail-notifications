<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Identifies a persisted tracking attempt without exposing its model.
 */
final readonly class TrackingAttempt
{
    /**
     * Create a persisted tracking attempt reference.
     */
    public function __construct(
        public string $id,
        public string $correlationId,
    ) {}
}
