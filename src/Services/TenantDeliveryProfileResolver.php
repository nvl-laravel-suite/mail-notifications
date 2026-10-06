<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Nvl\MailNotifications\Contracts\DeliveryProfileResolver;

/** Keeps the existing delivery-profile entrypoint while delegating to the selected adapter. */
final readonly class TenantDeliveryProfileResolver
{
    /** Create the package-owned profile reader. */
    public function __construct(private DeliveryProfileResolver $profiles) {}

    /** Return the approved profile selected by the active integration. */
    public function resolve(): ?string
    {
        return $this->profiles->resolve();
    }
}
