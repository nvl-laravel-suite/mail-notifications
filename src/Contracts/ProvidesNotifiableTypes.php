<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

/**
 * Supplies host-owned notifiable aliases to the package registry.
 */
interface ProvidesNotifiableTypes
{
    public const string TAG = 'mail-notifications.notifiable-types';

    /**
     * Return host aliases keyed by their stable public names.
     *
     * @return array<string, class-string<MailTrackable>>
     */
    public function mailNotificationNotifiableTypes(): array;
}
