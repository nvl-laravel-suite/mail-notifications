<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use DomainException;
use Nvl\MailNotifications\Contracts\MailTrackable;
use Nvl\MailNotifications\Contracts\ProvidesNotifiableTypes;

/**
 * Holds host-owned notifiable aliases without importing host models.
 */
final class MailNotificationNotifiableTypeRegistry
{
    /**
     * Registered aliases keyed by their stable public names.
     *
     * @var array<string, class-string<MailTrackable>>
     */
    private array $types = [];

    /**
     * Create the registry from configuration and tagged providers.
     *
     * @param  iterable<mixed>  $providers
     * @param  array<array-key, mixed>  $configuredTypes
     */
    public function __construct(iterable $providers = [], array $configuredTypes = [])
    {
        $this->registerMany($configuredTypes);

        foreach ($providers as $provider) {
            if (! $provider instanceof ProvidesNotifiableTypes) {
                throw new DomainException(
                    'Mail notification notifiable type providers must implement ProvidesNotifiableTypes.',
                );
            }

            $this->registerMany($provider->mailNotificationNotifiableTypes());
        }
    }

    /**
     * Resolve one registered alias.
     *
     * @return class-string<MailTrackable>|null
     */
    public function resolve(string $alias): ?string
    {
        return $this->types[trim($alias)] ?? null;
    }

    /**
     * Return every registered alias.
     *
     * @return array<string, class-string<MailTrackable>>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Register a host-owned alias map.
     *
     * @param  array<array-key, mixed>  $types
     */
    private function registerMany(array $types): void
    {
        foreach ($types as $alias => $class) {
            if (! is_string($alias)
                || trim($alias) === ''
                || mb_strlen(trim($alias)) > 128) {
                throw new DomainException(
                    'Mail notification notifiable aliases must contain 1 to 128 characters.',
                );
            }

            if (! is_string($class) || ! is_a($class, MailTrackable::class, true)) {
                throw new DomainException(sprintf(
                    'Mail notification notifiable type [%s] must implement [%s].',
                    is_scalar($class) ? (string) $class : get_debug_type($class),
                    MailTrackable::class,
                ));
            }

            $normalizedAlias = trim($alias);
            $existing = $this->types[$normalizedAlias] ?? null;

            if ($existing !== null && $existing !== $class) {
                throw new DomainException(sprintf(
                    'Mail notification notifiable alias [%s] is already registered.',
                    $normalizedAlias,
                ));
            }

            $this->types[$normalizedAlias] = $class;
        }
    }
}
