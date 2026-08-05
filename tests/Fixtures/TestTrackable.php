<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Nvl\MailNotifications\Contracts\MailTrackable;

/**
 * Provides a stable host-like notifiable reference for package tests.
 */
final readonly class TestTrackable implements MailTrackable
{
    /**
     * Create the notifiable fixture.
     */
    public function __construct(
        private string $identifier,
    ) {}

    /**
     * Return the fixture notifiable alias.
     */
    public function mailNotificationType(): string
    {
        return 'test-account';
    }

    /**
     * Return the fixture notifiable identifier.
     */
    public function mailNotificationIdentifier(): string
    {
        return $this->identifier;
    }
}
