<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use DomainException;
use Nvl\MailNotifications\Contracts\ProviderAdapter;

/**
 * Resolves configured provider adapters by their stable public names.
 */
final class ProviderRegistry
{
    /**
     * Registered adapters keyed by normalized provider name.
     *
     * @var array<string, ProviderAdapter>
     */
    private array $adapters = [];

    /**
     * Create the provider registry from configured and tagged adapters.
     *
     * @param  iterable<mixed>  $adapters
     */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            if (! $adapter instanceof ProviderAdapter) {
                throw new DomainException(
                    'Mail provider adapters must implement ProviderAdapter.',
                );
            }

            $name = trim($adapter->name());

            if ($name === '' || mb_strlen($name) > 128) {
                throw new DomainException(
                    'Mail provider adapter names must contain 1 to 128 characters.',
                );
            }

            $existing = $this->adapters[$name] ?? null;

            if ($existing instanceof ProviderAdapter && $existing !== $adapter) {
                throw new DomainException(
                    "Mail provider adapter [{$name}] is already registered.",
                );
            }

            $this->adapters[$name] = $adapter;
        }
    }

    /**
     * Resolve one provider adapter or reject an unknown name.
     */
    public function resolve(string $provider): ProviderAdapter
    {
        $name = trim($provider);
        $adapter = $this->adapters[$name] ?? null;

        if (! $adapter instanceof ProviderAdapter) {
            throw new DomainException(
                "Mail provider adapter [{$name}] is not registered.",
            );
        }

        return $adapter;
    }

    /**
     * Return all provider adapters keyed by stable name.
     *
     * @return array<string, ProviderAdapter>
     */
    public function all(): array
    {
        return $this->adapters;
    }
}
