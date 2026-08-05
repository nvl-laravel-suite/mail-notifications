<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Contracts\SensitiveDataTransformer;
use RuntimeException;

/**
 * Simulates a self-identifying host transformer with retained historical keys.
 */
final readonly class RotatingSensitiveDataTransformer implements SensitiveDataTransformer
{
    /**
     * Create the rotating transformer fixture.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Prefix a scope-bound payload with the fixture's current key identifier.
     */
    public function transform(string $scope, string $plaintext): string
    {
        return $this->currentKey().':'.base64_encode(
            $scope."\0".$plaintext,
        );
    }

    /**
     * Restore payloads protected by the current or retained fixture keys.
     */
    public function restore(string $scope, string $transformed): string
    {
        [$key, $payload] = array_pad(
            explode(':', $transformed, 2),
            2,
            null,
        );

        if (! is_string($key)
            || ! is_string($payload)
            || ! in_array($key, $this->readableKeys(), true)) {
            throw new RuntimeException(
                'The fixture transformer key is unavailable.',
            );
        }

        $decoded = base64_decode($payload, true);
        $prefix = $scope."\0";

        if (! is_string($decoded) || ! str_starts_with($decoded, $prefix)) {
            throw new RuntimeException(
                'The fixture transformer scope is invalid.',
            );
        }

        return substr($decoded, strlen($prefix));
    }

    /**
     * Resolve the current fixture key identifier.
     */
    private function currentKey(): string
    {
        $key = $this->config->get(
            'mail-notifications-tests.sensitive_storage.current_key',
            'key-v1',
        );

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException(
                'The fixture transformer current key is invalid.',
            );
        }

        return trim($key);
    }

    /**
     * Resolve current and retained fixture key identifiers.
     *
     * @return list<string>
     */
    private function readableKeys(): array
    {
        $previous = $this->config->get(
            'mail-notifications-tests.sensitive_storage.previous_keys',
            [],
        );

        if (! is_array($previous)) {
            throw new RuntimeException(
                'The fixture transformer previous keys are invalid.',
            );
        }

        return array_values(array_unique([
            $this->currentKey(),
            ...array_values(array_filter(
                $previous,
                static fn (mixed $key): bool => is_string($key)
                    && trim($key) !== '',
            )),
        ]));
    }
}
