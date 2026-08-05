<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Nvl\MailNotifications\Contracts\ProviderMessageIdResolver;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\TransportResult;

/**
 * Exercises standalone message identifier resolver registration through configuration.
 */
final class PluggedMessageIdResolver implements ProviderMessageIdResolver
{
    /**
     * Determine whether the fixture owns this mailer.
     */
    public function supports(TransportResult $result): bool
    {
        return $result->mailer === 'plugged-resolver';
    }

    /**
     * Return a stable provider identity for the fixture transport.
     */
    public function resolve(TransportResult $result): ProviderMessageId
    {
        return new ProviderMessageId(
            provider: 'plugged-resolver',
            value: $result->message->getMessageId(),
        );
    }
}
