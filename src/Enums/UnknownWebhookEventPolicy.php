<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

use Nvl\MailNotifications\Exceptions\MailTrackingException;

/**
 * Defines how authenticated but unsupported provider webhook events are handled.
 */
enum UnknownWebhookEventPolicy: string
{
    case Acknowledge = 'acknowledge';
    case Reject = 'reject';

    /**
     * Resolve a strict policy from package configuration.
     */
    public static function fromConfig(mixed $value): self
    {
        if (! is_string($value)) {
            throw new MailTrackingException(
                'The unknown webhook event policy must be [acknowledge] or [reject].',
            );
        }

        $policy = self::tryFrom(mb_strtolower(trim($value)));

        if (! $policy instanceof self) {
            throw new MailTrackingException(
                'The unknown webhook event policy must be [acknowledge] or [reject].',
            );
        }

        return $policy;
    }
}
