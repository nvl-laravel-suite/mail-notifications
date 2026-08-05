<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Nvl\MailNotifications\ValueObjects\RemoteWebhookManagementResult;
use Nvl\MailNotifications\ValueObjects\RemoteWebhookRemoveOptions;
use Nvl\MailNotifications\ValueObjects\RemoteWebhookSyncOptions;

/**
 * Manages one provider's remote webhook registration through explicit operator commands.
 */
interface RemoteWebhookManager
{
    public const string TAG = 'mail-notifications.remote-webhook-managers';

    /**
     * Return the stable provider name used by operator commands.
     */
    public function provider(): string;

    /**
     * Determine whether remote webhook management is explicitly enabled.
     */
    public function enabled(): bool;

    /**
     * Validate enabled remote management configuration without network access.
     */
    public function validateConfiguration(): void;

    /**
     * Create, compare, or update the configured remote webhook.
     */
    public function sync(
        RemoteWebhookSyncOptions $options,
    ): RemoteWebhookManagementResult;

    /**
     * Remove configured-name or explicitly all domain webhooks.
     */
    public function remove(
        RemoteWebhookRemoveOptions $options,
    ): RemoteWebhookManagementResult;
}
