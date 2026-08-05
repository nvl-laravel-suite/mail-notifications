<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use JsonException;
use Nvl\MailNotifications\Contracts\SensitiveDataTransformer;
use Nvl\MailNotifications\Exceptions\SensitiveStorageException;
use Nvl\MailNotifications\Exceptions\UnreadableSensitiveDataException;
use Throwable;

/**
 * Serializes sensitive arrays through an explicit host-owned storage transformer.
 */
final readonly class SensitiveStorageCodec
{
    private const string ENVELOPE_MARKER =
        '__nvl_mail_notifications_sensitive_01hprivacy';

    private const int LEGACY_ENVELOPE_VERSION = 1;

    private const int ENVELOPE_VERSION = 2;

    private const string ENVELOPE_ENCODING = 'base64';

    /**
     * Create the sensitive array storage codec.
     */
    public function __construct(
        private SensitiveStorageConfiguration $configuration,
        private ?SensitiveDataTransformer $transformer,
    ) {}

    /**
     * Encode one sensitive array for a JSON database column.
     *
     * @param  array<array-key, mixed>|null  $value
     */
    public function encodeArray(string $scope, ?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $this->assertScope($scope);

        if (! $this->configuration->enabled()) {
            $this->assertMarkerIsNotPlaintext($value);

            return $this->encodeJson($value);
        }

        if (! $this->transformer instanceof SensitiveDataTransformer) {
            throw new SensitiveStorageException(
                'Enabled mail notification sensitive storage has no transformer instance.',
            );
        }

        $plaintext = $this->encodeJson($value);

        try {
            $transformed = $this->transformer->transform(
                $scope,
                $plaintext,
            );
        } catch (Throwable $exception) {
            throw new SensitiveStorageException(
                'The mail notification sensitive storage transformer could not protect a value.',
                previous: $exception,
            );
        }

        return $this->encodeProtectedPayload($transformed);
    }

    /**
     * Decode one plaintext or protected JSON database value.
     *
     * @return array<array-key, mixed>|null
     */
    public function decodeArray(string $scope, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $this->assertScope($scope);
        $decoded = $this->decodeStoredJson($value);

        if (! array_key_exists(self::ENVELOPE_MARKER, $decoded)) {
            return $decoded;
        }

        $transformed = $this->decodeProtectedPayload($decoded);

        if (! $this->configuration->enabled()
            || ! $this->transformer instanceof SensitiveDataTransformer) {
            throw new UnreadableSensitiveDataException(
                'Stored mail notification sensitive data is protected but sensitive storage is disabled or unavailable.',
            );
        }

        try {
            $plaintext = $this->transformer->restore(
                $scope,
                $transformed,
            );
            $restored = json_decode(
                $plaintext,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $exception) {
            throw new UnreadableSensitiveDataException(
                'Stored mail notification sensitive data cannot be restored with the configured transformer keys or profiles.',
                previous: $exception,
            );
        }

        if (! is_array($restored)) {
            throw new UnreadableSensitiveDataException(
                'The mail notification sensitive storage transformer restored a non-array value.',
            );
        }

        return $restored;
    }

    /**
     * Validate one in-memory round trip without reading or mutating storage.
     */
    public function assertReady(): void
    {
        $transformerClass = $this->configuration->transformerClass();

        if ($transformerClass === null) {
            return;
        }

        if (! $this->transformer instanceof SensitiveDataTransformer) {
            throw new SensitiveStorageException(
                'Configured mail notification sensitive storage has no transformer instance.',
            );
        }

        $probe = [
            'contract' => 'mail-notifications-sensitive-storage',
        ];
        $plaintext = $this->encodeJson($probe);

        try {
            $transformed = $this->transformer->transform(
                'configuration.probe',
                $plaintext,
            );
            $stored = $this->encodeProtectedPayload($transformed);
            $payload = $this->decodeProtectedPayload(
                $this->decodeStoredJson($stored),
            );
            $restored = $this->transformer->restore(
                'configuration.probe',
                $payload,
            );
        } catch (Throwable $exception) {
            throw new SensitiveStorageException(
                'The mail notification sensitive storage transformer failed its readiness probe.',
                previous: $exception,
            );
        }

        if ($restored !== $plaintext) {
            throw new SensitiveStorageException(
                'The mail notification sensitive storage transformer failed its round-trip readiness probe.',
            );
        }
    }

    /**
     * Determine whether a host transformer is configured for a future or active rollout.
     */
    public function hasConfiguredTransformer(): bool
    {
        return $this->configuration->transformerClass() !== null;
    }

    /**
     * Encode one JSON value without leaking serialization details.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new SensitiveStorageException(
                'Mail notification sensitive data must be JSON serializable.',
                previous: $exception,
            );
        }
    }

    /**
     * Wrap arbitrary transformer bytes in a JSON-safe versioned envelope.
     */
    private function encodeProtectedPayload(string $transformed): string
    {
        if ($transformed === ''
            || strlen($transformed)
                > $this->configuration->maximumTransformedBytes()) {
            throw new SensitiveStorageException(
                'The mail notification sensitive storage transformer returned an empty or oversized payload.',
            );
        }

        return $this->encodeJson([
            self::ENVELOPE_MARKER => [
                'version' => self::ENVELOPE_VERSION,
                'encoding' => self::ENVELOPE_ENCODING,
                'payload' => base64_encode($transformed),
            ],
        ]);
    }

    /**
     * Restore opaque transformer bytes from a supported protection envelope.
     *
     * @param  array<array-key, mixed>  $decoded
     */
    private function decodeProtectedPayload(array $decoded): string
    {
        $envelope = $decoded[self::ENVELOPE_MARKER] ?? null;
        $version = is_array($envelope)
            ? $envelope['version'] ?? null
            : null;
        $payload = is_array($envelope)
            ? $envelope['payload'] ?? null
            : null;

        if (count($decoded) !== 1
            || ! is_array($envelope)
            || ! is_int($version)
            || ! is_string($payload)
            || $payload === '') {
            throw new UnreadableSensitiveDataException(
                'Stored mail notification sensitive data has a malformed protection envelope.',
            );
        }

        if ($version === self::LEGACY_ENVELOPE_VERSION) {
            if (count($envelope) !== 2) {
                throw new UnreadableSensitiveDataException(
                    'Stored mail notification sensitive data has a malformed protection envelope.',
                );
            }

            $transformed = $payload;
        } elseif ($version === self::ENVELOPE_VERSION) {
            $maximumEncodedBytes = 4 * intdiv(
                $this->configuration->maximumTransformedBytes() + 2,
                3,
            );

            if (count($envelope) !== 3
                || ($envelope['encoding'] ?? null)
                    !== self::ENVELOPE_ENCODING) {
                throw new UnreadableSensitiveDataException(
                    'Stored mail notification sensitive data has a malformed protection envelope.',
                );
            }

            if (strlen($payload) > $maximumEncodedBytes) {
                throw new UnreadableSensitiveDataException(
                    'Stored mail notification sensitive data exceeds the configured transformed byte limit.',
                );
            }

            $transformed = base64_decode($payload, true);

            if (! is_string($transformed)
                || $transformed === ''
                || ! hash_equals(base64_encode($transformed), $payload)) {
                throw new UnreadableSensitiveDataException(
                    'Stored mail notification sensitive data has a malformed protection envelope.',
                );
            }
        } else {
            throw new UnreadableSensitiveDataException(
                'Stored mail notification sensitive data has a malformed protection envelope.',
            );
        }

        if (strlen($transformed)
            > $this->configuration->maximumTransformedBytes()) {
            throw new UnreadableSensitiveDataException(
                'Stored mail notification sensitive data exceeds the configured transformed byte limit.',
            );
        }

        return $transformed;
    }

    /**
     * Decode one database JSON value without accepting invalid history.
     *
     * @return array<array-key, mixed>
     */
    private function decodeStoredJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new UnreadableSensitiveDataException(
                'Stored mail notification sensitive data is not valid JSON.',
            );
        }

        try {
            $decoded = json_decode(
                $value,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new UnreadableSensitiveDataException(
                'Stored mail notification sensitive data is not valid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new UnreadableSensitiveDataException(
                'Stored mail notification sensitive data is not a JSON array or object.',
            );
        }

        return $decoded;
    }

    /**
     * Prevent reserved protection metadata from masquerading as plaintext.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function assertMarkerIsNotPlaintext(array $value): void
    {
        if (array_key_exists(self::ENVELOPE_MARKER, $value)) {
            throw new SensitiveStorageException(
                'Mail notification sensitive data uses a reserved storage envelope key.',
            );
        }
    }

    /**
     * Require a stable semantic scope for transformer domain separation.
     */
    private function assertScope(string $scope): void
    {
        if (trim($scope) === '') {
            throw new SensitiveStorageException(
                'Mail notification sensitive storage scope cannot be empty.',
            );
        }
    }
}
