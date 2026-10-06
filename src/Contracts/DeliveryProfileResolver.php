<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

/**
 * Selects a deployment-approved Laravel mailer for scheduled delivery.
 *
 * @api
 */
interface DeliveryProfileResolver
{
    /** Return a named mailer, or null to retain Laravel's configured default. */
    public function resolve(): ?string;
}
