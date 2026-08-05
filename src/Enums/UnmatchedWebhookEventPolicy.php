<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

use Nvl\MailNotifications\Exceptions\MailTrackingException;

/**
 * Defines how authenticated delivery events without a tracked message are handled.
 */
enum UnmatchedWebhookEventPolicy: string
{
    case RetryThenAcknowledge = 'retry_then_acknowledge';
    case Reject = 'reject';
    case Acknowledge = 'acknowledge';

    /**
     * Resolve a strict unmatched-event policy from package configuration.
     */
    public static function fromConfig(mixed $value): self
    {
        if (! is_string($value)) {
            throw new MailTrackingException(
                'The unmatched webhook event policy must be [retry_then_acknowledge], [reject], or [acknowledge].',
            );
        }

        $policy = self::tryFrom(mb_strtolower(trim($value)));

        if (! $policy instanceof self) {
            throw new MailTrackingException(
                'The unmatched webhook event policy must be [retry_then_acknowledge], [reject], or [acknowledge].',
            );
        }

        return $policy;
    }
}
