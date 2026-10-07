<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/**
 * Admits native Mail fixture owners and applies the current root ownership.
 *
 * @internal
 */
final class FactoryGuard
{
    /** Require the unchanged persisted parent on the fixture connection and in the current tenant. */
    public static function parent(Model $owner, Model $child): int|string
    {
        $key = $owner->getKey();
        if (! $owner->exists || (! is_string($key) && ! is_int($key)) || $key === ''
            || $owner->getRawOriginal($owner->getKeyName()) !== $key
            || $owner->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Mail fixture owners require a persisted native model on the fixture connection.');
        }
        if (config('nvl-tenancy.enabled') === true) {
            $container = Container::getInstance();
            $resource = $container->make(TenantResourceRegistry::class)->forModel($owner);
            $container->make(TenantBoundary::class)->assertRecord($owner, $resource->key);
        }

        return $key;
    }

    /** Revalidate expanded native owner facts before applying admitted root attributes. */
    public static function prepare(Model $model, string $resource): void
    {
        if (config('nvl-tenancy.enabled') !== true) {
            return;
        }
        $type = $model->getAttribute('notifiable_type');
        $key = $model->getAttribute('notifiable_id');
        if ($type !== null || $key !== null) {
            $class = is_string($type) ? (Relation::getMorphedModel($type) ?? $type) : null;
            if ($class === null || ! is_a($class, Model::class, true) || $class === Model::class
                || (! is_string($key) && ! is_int($key)) || $key === '') {
                throw new InvalidArgumentException('Mail fixture ownership requires a concrete native morph identity.');
            }
            $owner = new $class;
            if ($owner->getConnection() !== $model->getConnection()) {
                throw new InvalidArgumentException('Mail fixture owners require the fixture connection.');
            }
            $owner = $owner->newQuery()->findOrFail($key);
            self::parent($owner, $model);
            if ($owner->getMorphClass() !== $type) {
                throw new InvalidArgumentException('Mail fixture owners require their native morph identity.');
            }
        }
        $model->forceFill(Container::getInstance()->make(TenantBoundary::class)->attributes($resource));
    }

    private function __construct() {}
}
