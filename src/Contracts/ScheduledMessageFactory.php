<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Mail\Mailable;
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;

/**
 * Rebuilds one host-owned Mailable from a stable alias and versioned payload.
 */
interface ScheduledMessageFactory
{
    public const string TAG = 'mail-notifications.scheduled-message-factories';

    /**
     * Return the stable persisted alias owned by this factory.
     */
    public function alias(): string;

    /**
     * Determine whether the factory understands a persisted payload version.
     */
    public function supportsVersion(int $version): bool;

    /**
     * Validate one versioned payload before persistence or delivery.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validate(int $version, array $payload): void;

    /**
     * Rebuild the host-owned Mailable without choosing its recipients.
     */
    public function make(ScheduledMessageData $message): Mailable;
}
