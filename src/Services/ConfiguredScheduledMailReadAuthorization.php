<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization;
use Nvl\MailNotifications\Enums\ScheduledMailReadAbility;
use Nvl\MailNotifications\Models\ScheduledMailMessage;

/**
 * Fail-closed scheduled-mail authorization backed by an optional host callback.
 *
 * @internal
 */
final class ConfiguredScheduledMailReadAuthorization implements ScheduledMailReadAuthorization
{
    /**
     * Delegate scheduled reads to the configured host policy and fail closed when authority is absent.
     */
    public function authorize(
        ScheduledMailReadAbility $ability,
        Authenticatable $actor,
        ?ScheduledMailMessage $message = null,
    ): void {
        $callback = config('nvl-mail-notifications.management.scheduled_authorization.callback');
        $allowed = is_callable($callback)
            && $callback($ability, $actor, $message) === true;

        if (! $allowed) {
            throw new AuthorizationException(
                "The actor is not authorized to [{$ability->value}] scheduled mail.",
            );
        }
    }
}
