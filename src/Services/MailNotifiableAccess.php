<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\MailNotifications\Compatibility\LegacyMailNotifiableAccess;
use Nvl\MailNotifications\Contracts\MailTrackable;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;

/** Resolves persisted notifiables canonically without treating identity metadata as an owner grant. @internal */
final readonly class MailNotifiableAccess
{
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipBoundary $boundary, private LegacyMailNotifiableAccess $legacy) {}

    /** @param class-string<MailTrackable> $class */
    public function assert(string $class, string $identifier): void
    {
        if (config('nvl-ownership.enabled') !== true) {
            $this->legacy->assert($class, $identifier);

            return;
        }
        if (! is_subclass_of($class, Model::class)) {
            return;
        }
        $model = new $class;
        $resource = null;
        foreach ($this->resources->all() as $definition) {
            if ($definition->model === $class) {
                $resource = $definition->key;
                break;
            }
        }
        if ($resource === null) {
            $this->resources->assertUnclaimed($model);
            (new PersistedOwnershipStorage)->assertUnadopted($model->getConnection(), physicalTable: $model->getTable());
        }
        $query = $model->newQuery()->useWritePdo();
        $record = ($resource === null ? $query : $this->boundary->query($query, $resource))->find($identifier);
        $key = $record?->getKey();
        if (! $record instanceof Model || (! is_int($key) && ! is_string($key)) || (string) $key !== $identifier) {
            throw new OwnershipViolation('Mail notifiable requires a live canonical persisted identity.');
        }
        if ($resource !== null) {
            $this->boundary->assertRecord($record, $resource);
        }
    }
}
