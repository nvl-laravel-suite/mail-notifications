<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Ownership;

use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Services\MailModelRegistry;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Declares independent delivery facts and inherited authenticated provider events.
 *
 * @internal
 */
final readonly class MailResourceRegistrar
{
    public function __construct(private MailModelRegistry $models) {}

    /**
     * Register the package resource declarations without granting a host actor authority.
     */
    public function register(OwnershipResourceRegistry $resources, bool $owned): void
    {
        $resources->register(new OwnershipResourceDefinition('mail.notifications', 'mail-notifications', $this->models->notificationClass(),
            $owned ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped(), allowsCentral: $owned,
            immutableFields: $owned ? ['correlation_id', 'queue_reference'] : []));
        $resources->register(new OwnershipResourceDefinition('mail.scheduled', 'mail-notifications', $this->models->scheduledClass(),
            $owned ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped(), allowsCentral: $owned));
        $resources->register(new OwnershipResourceDefinition('mail.events', 'mail-notifications', MailNotificationEvent::class,
            $owned ? OwnershipDefinition::inherited('mailNotification') : OwnershipDefinition::unscoped(), parentResource: $owned ? 'mail.notifications' : null, allowsCentral: $owned,
            immutableFields: $owned ? ['provider', 'provider_event_id', 'provider_message_id', 'normalized_type', 'occurred_at'] : []));
    }
}
