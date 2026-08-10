<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Enums\MailNotificationReadAbility;
use Nvl\MailNotifications\Models\MailNotification;

/**
 * Fail-closed read authorization backed by an optional host callback.
 */
final class ConfiguredMailNotificationReadAuthorization implements MailNotificationReadAuthorization
{
    public function authorize(
        MailNotificationReadAbility $ability,
        Authenticatable $actor,
        ?MailNotification $notification = null,
    ): void {
        $callback = config('mail-notifications.management.authorization.callback');
        $allowed = is_callable($callback)
            && $callback($ability, $actor, $notification) === true;

        if (! $allowed) {
            throw new AuthorizationException(
                "The actor is not authorized to [{$ability->value}] mail notifications.",
            );
        }
    }
}
