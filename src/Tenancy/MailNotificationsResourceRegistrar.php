<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tenancy;

use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\Support\Tenancy\Enums\TenantResourceKind;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

/** Registers persisted mail roots and provider events under canonical notification ownership. */
final readonly class MailNotificationsResourceRegistrar
{
    /** Register Mail ownership and its reviewed adoption adapter. */
    public function register(TenantResourceRegistry $resources, ?TenantAdoptionRegistry $adapters = null): void
    {
        $resources->register(new TenantResourceDefinition(
            key: 'mail.scheduled',
            family: 'mail-notifications',
            model: ScheduledMailMessage::class,
            allowsPlatformRows: true,
        ));
        $resources->register(new TenantResourceDefinition(
            key: 'mail.notifications',
            family: 'mail-notifications',
            model: MailNotification::class,
            allowsPlatformRows: true,
        ));
        $resources->register(new TenantResourceDefinition(
            key: 'mail.events',
            family: 'mail-notifications',
            model: MailNotificationEvent::class,
            kind: TenantResourceKind::Inherited,
            parentResource: 'mail.notifications',
            parentRelation: 'mailNotification',
        ));
        $adapters?->register('mail-notifications', MailNotificationsAdoptionAdapter::class);
    }
}
