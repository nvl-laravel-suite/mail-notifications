<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Nvl\MailNotifications\Contracts\MailTrackable;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;

/** Validates a registered notifiable through its owning package resource declaration. */
final readonly class MailNotifiableTenantAccess
{
    /** Create the notifiable boundary. */
    public function __construct(
        private Repository $config,
        private TenantResourceRegistry $resources,
        private TenantBoundary $boundary,
    ) {}

    /** @param class-string<MailTrackable> $class */
    public function assert(string $class, string $identifier): void
    {
        if ($this->config->get('tenancy.enabled') !== true) {
            return;
        }
        if (! is_subclass_of($class, Model::class)) {
            throw new TenantBoundaryViolation('Tenant mail notifiables must expose registered model ownership.');
        }
        $resource = null;
        foreach ($this->resources->all() as $definition) {
            if ($definition->model === $class) {
                $resource = $definition->key;
                break;
            }
        }
        if ($resource === null) {
            throw new TenantBoundaryViolation('Tenant mail notifiable model ownership is not registered.');
        }
        /** @var Model $model */
        $model = new $class;
        $record = $this->boundary->query($model->newQuery(), $resource)->find($identifier);
        if (! $record instanceof Model) {
            throw new TenantBoundaryViolation('Mail notifiable is outside the active tenant.');
        }
        $this->boundary->assertRecord($record, $resource);
    }
}
