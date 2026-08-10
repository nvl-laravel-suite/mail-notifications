<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Enums\MailNotificationReadAbility;
use Nvl\MailNotifications\Models\MailNotification;

/**
 * Consumer-owned policy boundary for privileged delivery-history reads.
 */
interface MailNotificationReadAuthorization
{
    /**
     * Throw when the actor cannot perform the requested read.
     */
    public function authorize(
        MailNotificationReadAbility $ability,
        Authenticatable $actor,
        ?MailNotification $notification = null,
    ): void;
}
