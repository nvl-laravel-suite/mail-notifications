<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\MailTrackable;

/**
 * Carries a stable host-owned notifiable alias and identifier.
 */
final readonly class NotifiableReference
{
    public string $type;

    public string $identifier;

    /**
     * Create a stable notifiable reference.
     */
    public function __construct(
        string $type,
        string $identifier,
    ) {
        $normalizedType = trim($type);
        $normalizedIdentifier = trim($identifier);

        if ($normalizedType === '' || $normalizedIdentifier === '') {
            throw new InvalidArgumentException('Notifiable type and identifier cannot be empty.');
        }

        if (mb_strlen($normalizedType) > 128 || mb_strlen($normalizedIdentifier) > 128) {
            throw new InvalidArgumentException(
                'Notifiable types and identifiers may not exceed 128 characters.',
            );
        }

        $this->type = $normalizedType;
        $this->identifier = $normalizedIdentifier;
    }

    /**
     * Create a reference from a host model contract.
     */
    public static function fromTrackable(MailTrackable $trackable): self
    {
        return new self(
            type: $trackable->mailNotificationType(),
            identifier: $trackable->mailNotificationIdentifier(),
        );
    }
}
