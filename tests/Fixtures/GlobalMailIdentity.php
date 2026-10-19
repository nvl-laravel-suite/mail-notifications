<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\MailNotifications\Contracts\MailTrackable;

/** Global credential-like identity with a canonical live native key. */
final class GlobalMailIdentity extends Model implements MailTrackable
{
    protected $table = 'ownership_test_actors';

    public $timestamps = false;

    public function mailNotificationType(): string
    {
        return 'global.identity';
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
