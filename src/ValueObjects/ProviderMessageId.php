<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use InvalidArgumentException;

/**
 * Pairs a stable provider name with its accepted message identifier.
 */
final readonly class ProviderMessageId
{
    public string $provider;

    public string $value;

    /**
     * Create a provider message identity.
     */
    public function __construct(
        string $provider,
        string $value,
    ) {
        $normalizedProvider = trim($provider);
        $normalizedValue = trim($value);

        if ($normalizedProvider === '' || $normalizedValue === '') {
            throw new InvalidArgumentException('Provider name and message identifier cannot be empty.');
        }

        if (mb_strlen($normalizedProvider) > 128 || mb_strlen($normalizedValue) > 255) {
            throw new InvalidArgumentException(
                'Provider names may not exceed 128 characters and message identifiers may not exceed 255 characters.',
            );
        }

        $this->provider = $normalizedProvider;
        $this->value = $normalizedValue;
    }
}
