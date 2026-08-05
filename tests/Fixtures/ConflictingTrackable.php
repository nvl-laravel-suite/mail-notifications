<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Nvl\MailNotifications\Contracts\MailTrackable;

/**
 * Provides a second notifiable type for duplicate alias tests.
 */
final class ConflictingTrackable implements MailTrackable
{
    /**
     * Return the fixture notifiable alias.
     */
    public function mailNotificationType(): string
    {
        return 'conflicting-account';
    }

    /**
     * Return the fixture notifiable identifier.
     */
    public function mailNotificationIdentifier(): string
    {
        return 'conflicting';
    }
}
