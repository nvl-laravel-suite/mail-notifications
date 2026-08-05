<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

/**
 * Identifies one optional provider adapter without coupling core to its SDK.
 */
interface ProviderAdapter
{
    public const string CONTAINER_TAG = 'mail-notifications.provider-adapters';

    /**
     * Return the stable provider name exposed to core.
     */
    public function name(): string;
}
