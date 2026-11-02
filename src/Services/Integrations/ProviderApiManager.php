<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Integrations;

use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\ProviderApiAdapter;
use Nvl\MailNotifications\Models\Integration;
use Nvl\MailNotifications\Models\ProviderAccount;

/** Resolves the reviewed built-in adapters used by authorized provider operations.
 * @internal
 */
class ProviderApiManager
{
    /**
     * Resolve a reviewed administration adapter for this already authorized provider account.
     */
    public function for(ProviderAccount $account): ProviderApiAdapter
    {
        if (! in_array($account->provider, Integration::SUPPORTED_KEYS, true)) {
            throw new InvalidArgumentException('Unsupported integration driver.');
        }

        return new HttpProviderApiAdapter($account->provider);
    }

    /**
     * Report the reviewed driver's supported administrative operations, returning no capabilities for unknown keys.
     *
     * @return array{send: bool, domains: bool, senders: bool, message: bool, messages: bool}
     */
    public function capabilities(string $key): array
    {
        if (! in_array($key, Integration::SUPPORTED_KEYS, true)) {
            return ['send' => false, 'domains' => false, 'senders' => false, 'message' => false, 'messages' => false];
        }

        return (new HttpProviderApiAdapter($key))->capabilities();
    }
}
