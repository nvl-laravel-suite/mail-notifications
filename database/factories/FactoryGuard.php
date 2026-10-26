<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Admits native Mail fixture owners and applies the current root ownership.
 *
 * @internal
 */
final class FactoryGuard
{
    /** Require the unchanged persisted parent on the fixture connection. */
    public static function parent(Model $owner, Model $child): int|string
    {
        $key = $owner->getKey();
        if (! $owner->exists || (! is_string($key) && ! is_int($key)) || $key === ''
            || $owner->getRawOriginal($owner->getKeyName()) !== $key
            || $owner->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Mail fixture owners require a persisted native model on the fixture connection.');
        }

        return $key;
    }

    /** Revalidate expanded native owner facts before applying admitted root attributes. */
    public static function prepare(Model $model, string $resource): void
    {
        $type = $model->getAttribute('notifiable_type');
        $key = $model->getAttribute('notifiable_id');
        if ($type !== null || $key !== null) {
            $class = is_string($type) ? (Relation::getMorphedModel($type) ?? $type) : null;
            if ($class === null || (! is_string($key) && ! is_int($key)) || $key === '') {
                throw new InvalidArgumentException('Mail fixture ownership requires a concrete native morph identity.');
            }
            if (! is_a($class, Model::class, true) || $class === Model::class) {
                $model->forceFill(Container::getInstance()->make(OwnershipBoundary::class)->attributes($resource));

                return;
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
        $model->forceFill(Container::getInstance()->make(OwnershipBoundary::class)->attributes($resource));
    }

    private function __construct() {}
}
