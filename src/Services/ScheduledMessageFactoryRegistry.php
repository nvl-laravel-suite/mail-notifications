<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\Exceptions\ScheduledMailException;
use ReflectionClass;

/**
 * Resolves scheduled message factories by stable alias and payload version.
 */
final class ScheduledMessageFactoryRegistry
{
    private readonly ContainerContract $container;

    /**
     * Registered factories keyed by stable alias.
     *
     * @var array<string, class-string<ScheduledMessageFactory>|ScheduledMessageFactory>
     */
    private array $factories = [];

    /**
     * Create the registry from configured and tagged factories.
     *
     * @param  iterable<mixed>  $factories
     */
    public function __construct(iterable $factories = [], ?ContainerContract $container = null)
    {
        $this->container = $container ?? Container::getInstance();
        foreach ($factories as $factory) {
            if (is_string($factory)) {
                if (! is_a($factory, ScheduledMessageFactory::class, true)) {
                    throw new ScheduledMailException(
                        'Scheduled message factories must implement ScheduledMessageFactory.',
                    );
                }
                $factory = $this->instantiate($factory);
            } elseif (! is_object($factory)) {
                throw new ScheduledMailException(
                    'Scheduled message factories must implement ScheduledMessageFactory.',
                );
            }
            if (! $factory instanceof ScheduledMessageFactory) {
                throw new ScheduledMailException(
                    'Scheduled message factories must implement ScheduledMessageFactory.',
                );
            }
            $factoryClass = $factory::class;
            $reflection = new ReflectionClass($factory);
            $constructor = $reflection->getConstructor();
            $storedFactory = ! $reflection->isAnonymous()
                && ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0)
                ? $factoryClass
                : $factory;
            $alias = trim($factory->alias());

            if ($alias === '' || mb_strlen($alias) > 128) {
                throw new ScheduledMailException(
                    'Scheduled message factory aliases must contain 1 to 128 characters.',
                );
            }

            $existing = $this->factories[$alias] ?? null;
            $existingClass = is_string($existing)
                ? $existing
                : ($existing instanceof ScheduledMessageFactory ? $existing::class : null);
            if ($existing !== null && $existingClass !== $factoryClass) {
                throw new ScheduledMailException(
                    "Scheduled message factory [{$alias}] is already registered.",
                );
            }

            $this->factories[$alias] = $storedFactory;
        }
    }

    /**
     * Resolve one factory and require explicit version support.
     */
    public function resolve(string $alias, int $version): ScheduledMessageFactory
    {
        $normalizedAlias = trim($alias);
        $factoryClass = $this->factories[$normalizedAlias] ?? null;

        if ($factoryClass === null) {
            throw new ScheduledMailException(
                "Scheduled message factory [{$normalizedAlias}] is not registered.",
            );
        }
        $factory = is_string($factoryClass)
            ? $this->instantiate($factoryClass)
            : $factoryClass;
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
        return array_map(
            fn (string|ScheduledMessageFactory $factory): ScheduledMessageFactory => is_string($factory)
                ? $this->instantiate($factory)
                : $factory,
            $this->factories,
        );
    }

    /**
     * Resolve one validated factory class through the host container.
     *
     * @param  class-string<ScheduledMessageFactory>  $factory
     */
    private function instantiate(string $factory): ScheduledMessageFactory
    {
        $instance = $this->container->make($factory);
        if (! $instance instanceof ScheduledMessageFactory) {
            throw new ScheduledMailException(
                'Scheduled message factories must implement ScheduledMessageFactory.',
            );
        }

        return $instance;
    }
}
