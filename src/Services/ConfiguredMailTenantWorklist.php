<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\MailTenantWorklist;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;

/** Conservative worklist populated only by reviewed deployment configuration. */
final readonly class ConfiguredMailTenantWorklist implements MailTenantWorklist
{
    public function __construct(private Repository $config) {}

    public function activeTenantIds(): array
    {
        $configured = $this->config->get('mail-notifications.tenancy.active_tenant_worklist', []);
        if (! is_array($configured) || ! array_is_list($configured)
            || array_any($configured, static fn (mixed $id): bool => ! is_string($id) || ! Str::isUuid($id))) {
            throw new TenantConfigurationInvalid('The Mail tenant worklist must be a list of canonical UUIDs.');
        }
        $ids = array_values(array_unique($configured));
        sort($ids);

        return $ids;
    }
}
