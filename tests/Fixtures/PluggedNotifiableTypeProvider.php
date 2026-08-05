<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Nvl\MailNotifications\Contracts\ProvidesNotifiableTypes;

/**
 * Exercises host notifiable alias registration through a configured provider.
 */
final class PluggedNotifiableTypeProvider implements ProvidesNotifiableTypes
{
    /**
     * Return one provider-owned fixture alias.
     *
     * @return array<string, class-string<TestTrackable>>
     */
    public function mailNotificationNotifiableTypes(): array
    {
        return [
            'provided-trackable' => TestTrackable::class,
        ];
    }
}
