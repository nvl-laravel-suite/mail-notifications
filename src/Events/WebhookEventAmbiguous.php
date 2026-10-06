<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces a verified webhook that ambiguously matched tracked deliveries.
 *
 * @api
 */
final class WebhookEventAmbiguous implements DomainEvent
{
    /**
     * Create the privacy-safe webhook ambiguity event.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $providerEventId,
        public readonly ?string $providerMessageId,
        public readonly ?string $correlationId,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
