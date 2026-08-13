<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Enums\ScheduledMailReadAbility;
use Nvl\MailNotifications\Models\ScheduledMailMessage;

/**
 * Consumer-owned policy boundary for privileged scheduled-mail reads.
 */
interface ScheduledMailReadAuthorization
{
    /**
     * Throw when the actor cannot perform the requested read.
     */
    public function authorize(
        ScheduledMailReadAbility $ability,
        Authenticatable $actor,
        ?ScheduledMailMessage $message = null,
    ): void;
}
