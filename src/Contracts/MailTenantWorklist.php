<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

/** Host-supplied bounded active-tenant enumeration for mail workers and retention. */
interface MailTenantWorklist
{
    /** @return list<string> */
    public function activeTenantIds(): array;
}
