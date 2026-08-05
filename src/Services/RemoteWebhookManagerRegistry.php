<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use DomainException;
use Nvl\MailNotifications\Contracts\RemoteWebhookManager;

/**
 * Resolves configured remote webhook managers by stable provider name.
 */
final class RemoteWebhookManagerRegistry
{
    /**
     * Registered managers keyed by normalized provider name.
     *
     * @var array<string, RemoteWebhookManager>
     */
    private array $managers = [];

    /**
     * Create the registry from configured and tagged managers.
     *
     * @param  iterable<mixed>  $managers
     */
    public function __construct(iterable $managers = [])
    {
        foreach ($managers as $manager) {
            if (! $manager instanceof RemoteWebhookManager) {
                throw new DomainException(
                    'Remote webhook managers must implement RemoteWebhookManager.',
                );
            }

            $provider = trim($manager->provider());

            if ($provider === '' || mb_strlen($provider) > 128) {
                throw new DomainException(
                    'Remote webhook manager providers must contain 1 to 128 characters.',
                );
            }

            $existing = $this->managers[$provider] ?? null;

            if ($existing instanceof RemoteWebhookManager
                && $existing !== $manager) {
                throw new DomainException(
                    "Remote webhook manager [{$provider}] is already registered.",
                );
            }

            $this->managers[$provider] = $manager;
        }
    }

    /**
     * Resolve one configured remote webhook manager.
     */
    public function resolve(string $provider): RemoteWebhookManager
    {
        $normalizedProvider = trim($provider);
        $manager = $this->managers[$normalizedProvider] ?? null;

        if (! $manager instanceof RemoteWebhookManager) {
            throw new DomainException(
                "Remote webhook manager [{$normalizedProvider}] is not registered.",
            );
        }

        return $manager;
    }

    /**
     * Return every registered manager keyed by stable provider name.
     *
     * @return array<string, RemoteWebhookManager>
     */
    public function all(): array
    {
        return $this->managers;
    }
}
