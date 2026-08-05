<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Nvl\MailNotifications\Contracts\ProviderMessageIdResolver;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\TransportResult;
use UnexpectedValueException;

/**
 * Selects an optional provider resolver before the standard Symfony fallback.
 */
final class ProviderMessageIdRegistry
{
    /**
     * Validated provider-specific resolvers.
     *
     * @var list<ProviderMessageIdResolver>
     */
    private readonly array $resolvers;

    /**
     * Create the provider message identifier registry.
     *
     * @param  iterable<mixed>  $resolvers
     */
    public function __construct(
        iterable $resolvers,
        private readonly SymfonyMessageIdResolver $fallback,
    ) {
        $validatedResolvers = [];
        $seenResolvers = [];

        foreach ($resolvers as $resolver) {
            if (! $resolver instanceof ProviderMessageIdResolver) {
                throw new UnexpectedValueException(
                    'Mail message identifier resolvers must implement ProviderMessageIdResolver.',
                );
            }

            $resolverId = spl_object_id($resolver);

            if (isset($seenResolvers[$resolverId])) {
                continue;
            }

            $seenResolvers[$resolverId] = true;
            $validatedResolvers[] = $resolver;
        }

        $this->resolvers = $validatedResolvers;
    }

    /**
     * Resolve the best available provider message identity.
     */
    public function resolve(TransportResult $result): ProviderMessageId
    {
        foreach ($this->resolvers as $resolver) {
            if (! $resolver->supports($result)) {
                continue;
            }

            $messageId = $resolver->resolve($result);

            if ($messageId instanceof ProviderMessageId) {
                return $messageId;
            }
        }

        $fallback = $this->fallback->resolve($result);

        if (! $fallback instanceof ProviderMessageId) {
            throw new MailTrackingException(
                'The completed mail transport did not expose a message identifier.',
            );
        }

        return $fallback;
    }
}
