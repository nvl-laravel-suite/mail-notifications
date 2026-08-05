<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

/**
 * Identifies a host-owned model through a stable mail tracking alias.
 */
interface MailTrackable
{
    /**
     * Return the stable alias persisted for this notifiable type.
     */
    public function mailNotificationType(): string;

    /**
     * Return the stable identifier persisted for this notifiable instance.
     */
    public function mailNotificationIdentifier(): string;
}
