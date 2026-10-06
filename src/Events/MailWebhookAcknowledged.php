<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Announces an authenticated webhook acknowledged without lifecycle mutation.
 *
 * @api
 */
final class MailWebhookAcknowledged implements DomainEvent
{
    /**
     * Create the provider webhook acknowledgement event.
     */
    public function __construct(
        public readonly WebhookAcknowledgement $acknowledgement,
        public readonly int $schemaVersion = 1,
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
