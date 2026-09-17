<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Container\Container;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\Exceptions\ScheduledMailException;

/**
 * Resolves scheduled message factories by stable alias and payload version.
 */
final class ScheduledMessageFactoryRegistry
{
    private readonly Container $container;

    /**
     * Registered factories keyed by stable alias.
     *
     * @var array<string, class-string<ScheduledMessageFactory>>
     */
    private array $factories = [];

    /**
     * Create the registry from configured and tagged factories.
     *
     * @param  iterable<mixed>  $factories
     */
    public function __construct(iterable $factories = [], ?Container $container = null)
    {
        $this->container = $container ?? Container::getInstance();
        foreach ($factories as $factory) {
            $class = is_string($factory) ? $factory : (is_object($factory) ? $factory::class : null);
            if (! is_string($class) || ! is_a($class, ScheduledMessageFactory::class, true)) {
                throw new ScheduledMailException(
                    'Scheduled message factories must implement ScheduledMessageFactory.',
                );
            }

            $probe = $this->container->build($class);
            if (! $probe instanceof ScheduledMessageFactory) {
                throw new ScheduledMailException('Scheduled message factory bindings must resolve their declared contract.');
            }
            $alias = trim($probe->alias());

            if ($alias === '' || mb_strlen($alias) > 128) {
                throw new ScheduledMailException(
                    'Scheduled message factory aliases must contain 1 to 128 characters.',
                );
            }

            $existing = $this->factories[$alias] ?? null;

            if (is_string($existing) && $existing !== $class) {
                throw new ScheduledMailException(
                    "Scheduled message factory [{$alias}] is already registered.",
                );
            }

            $this->factories[$alias] = $class;
        }
    }

    /**
     * Resolve one factory and require explicit version support.
     */
    public function resolve(string $alias, int $version): ScheduledMessageFactory
    {
        $normalizedAlias = trim($alias);
        $class = $this->factories[$normalizedAlias] ?? null;

        if (! is_string($class)) {
            throw new ScheduledMailException(
                "Scheduled message factory [{$normalizedAlias}] is not registered.",
            );
        }
        $factory = $this->container->build($class);
        if (! $factory instanceof ScheduledMessageFactory) {
            throw new ScheduledMailException("Scheduled message factory [{$normalizedAlias}] did not resolve its contract.");
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
        $resolved = [];
        foreach ($this->factories as $alias => $class) {
            $factory = $this->container->build($class);
            if (! $factory instanceof ScheduledMessageFactory) {
                throw new ScheduledMailException("Scheduled message factory [{$alias}] did not resolve its contract.");
            }
            $resolved[$alias] = $factory;
        }

        return $resolved;
    }
}
