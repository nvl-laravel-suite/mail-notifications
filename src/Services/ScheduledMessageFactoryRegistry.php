<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\Exceptions\ScheduledMailException;

/**
 * Resolves scheduled message factories by stable alias and payload version.
 */
final class ScheduledMessageFactoryRegistry
{
    /**
     * Registered factories keyed by stable alias.
     *
     * @var array<string, ScheduledMessageFactory>
     */
    private array $factories = [];

    /**
     * Create the registry from configured and tagged factories.
     *
     * @param  iterable<mixed>  $factories
     */
    public function __construct(iterable $factories = [])
    {
        foreach ($factories as $factory) {
            if (! $factory instanceof ScheduledMessageFactory) {
                throw new ScheduledMailException(
                    'Scheduled message factories must implement ScheduledMessageFactory.',
                );
            }

            $alias = trim($factory->alias());

            if ($alias === '' || mb_strlen($alias) > 128) {
                throw new ScheduledMailException(
                    'Scheduled message factory aliases must contain 1 to 128 characters.',
                );
            }

            $existing = $this->factories[$alias] ?? null;

            if ($existing instanceof ScheduledMessageFactory
                && $existing !== $factory) {
                throw new ScheduledMailException(
                    "Scheduled message factory [{$alias}] is already registered.",
                );
            }

            $this->factories[$alias] = $factory;
        }
    }

    /**
     * Resolve one factory and require explicit version support.
     */
    public function resolve(string $alias, int $version): ScheduledMessageFactory
    {
        $normalizedAlias = trim($alias);
        $factory = $this->factories[$normalizedAlias] ?? null;

        if (! $factory instanceof ScheduledMessageFactory) {
            throw new ScheduledMailException(
                "Scheduled message factory [{$normalizedAlias}] is not registered.",
            );
        }

        if ($version < 1 || ! $factory->supportsVersion($version)) {
            throw new ScheduledMailException(sprintf(
                'Scheduled message factory [%s] does not support payload version [%d].',
                $normalizedAlias,
                $version,
            ));
        }

        return $factory;
    }

    /**
     * Return all factories keyed by their stable aliases.
     *
     * @return array<string, ScheduledMessageFactory>
     */
    public function all(): array
    {
        return $this->factories;
    }
}
