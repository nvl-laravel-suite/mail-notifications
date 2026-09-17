<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Contracts\MailTenantWorklist;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Executes mail workers and retention once per explicit active tenant partition. */
final readonly class MailTenantOperations
{
    public function __construct(
        private Repository $config,
        private TenantContext $context,
        private TenantRunner $tenants,
        private MailTenantWorklist $worklist,
    ) {}

    /**
     * @template T
     * @param Closure(): T $operation
     * @return list<T>
     */
    public function run(Closure $operation): array
    {
        if ($this->config->get('tenancy.enabled') !== true) {
            return [$operation()];
        }
        $snapshot = $this->context->snapshot();
        if ($snapshot->mode === TenantContextMode::Tenant) {
            return [$operation()];
        }
        if (! in_array($snapshot->mode, [TenantContextMode::Unresolved, TenantContextMode::Platform], true)) {
            throw new TenantBoundaryViolation('Mail maintenance requires an admitted tenant or platform worklist.');
        }
        $ids = $snapshot->mode === TenantContextMode::Platform
            ? $this->worklist->activeTenantIds()
            : $this->tenants->platform(
                new PlatformOperation('mail.maintenance.enumerate', 'system', 'mail-maintenance'),
                fn (): array => $this->worklist->activeTenantIds(),
            );
        $results = [];
        foreach ($ids as $id) {
            $results[] = $this->tenants->run(new TenantId($id), $operation);
        }

        return $results;
    }
}
