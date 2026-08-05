<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\TransportResult;

/**
 * Resolves a provider message identifier from a completed transport result.
 */
interface ProviderMessageIdResolver
{
    public const string TAG = 'mail-notifications.message-id-resolvers';

    /**
     * Determine whether this resolver understands the transport result.
     */
    public function supports(TransportResult $result): bool;

    /**
     * Resolve the provider identity attached to the transport result.
     */
    public function resolve(TransportResult $result): ?ProviderMessageId;
}
