<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\MailNotifications\Contracts\MailTrackable;
use Nvl\MailNotifications\Models\MailNotification;

/** Deliberately unguarded alias over adopted storage; native references must reject it. */
final class ShadowMailNotifiable extends Model implements MailTrackable
{
    public function getTable(): string
    {
        return (new MailNotification)->getTable();
    }

    public function mailNotificationType(): string
    {
        return 'shadow';
    }

    public function mailNotificationIdentifier(): string
    {
        $key = $this->getKey();
        if (! is_int($key) && ! is_string($key)) {
            throw new \InvalidArgumentException('Mail identity requires a persisted scalar key.');
        }

        return (string) $key;
    }
}
