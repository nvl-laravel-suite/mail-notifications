<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

/**
 * Validates an optional provider adapter's operator-supplied configuration.
 */
interface ProviderConfigurationValidator
{
    /**
     * Reject invalid provider configuration before runtime traffic arrives.
     */
    public function validateConfiguration(bool $webhooksEnabled): void;
}
