<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\SensitiveDataRedactor;
use Nvl\MailNotifications\Exceptions\MailTrackingException;

/**
 * Recursively masks configured sensitive keys before package persistence.
 */
final readonly class DefaultSensitiveDataRedactor implements SensitiveDataRedactor
{
    /**
     * Create the default recursive redactor.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Return a recursively redacted copy of the supplied data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        $configuredKeys = $this->config->get('mail-notifications.privacy.redacted_keys', []);

        if (! is_array($configuredKeys)) {
            throw new MailTrackingException(
                'Mail notification redacted keys configuration must be an array.',
            );
        }

        $redactedKeys = array_values(array_unique(array_filter(array_map(
            fn (mixed $key): string => is_string($key)
                ? $this->canonicalKey($key)
                : '',
            $configuredKeys,
        ))));
        $maximumDepth = $this->config->get(
            'mail-notifications.privacy.max_depth',
            16,
        );

        if (! is_int($maximumDepth)
            || $maximumDepth < 1
            || $maximumDepth > 64) {
            throw new MailTrackingException(
                'Mail notification metadata depth must be an integer between 1 and 64.',
            );
        }

        $maximumItems = $this->boundedInteger(
            key: 'mail-notifications.privacy.max_items',
            default: 1_000,
            minimum: 1,
            maximum: 100_000,
            label: 'metadata item limit',
        );
        $maximumStringBytes = $this->boundedInteger(
            key: 'mail-notifications.privacy.max_string_bytes',
            default: 16_384,
            minimum: 1,
            maximum: 1_048_576,
            label: 'metadata string byte limit',
        );
        $maximumTotalBytes = $this->boundedInteger(
            key: 'mail-notifications.privacy.max_total_bytes',
            default: 65_536,
            minimum: 1,
            maximum: 10_485_760,
            label: 'metadata total byte limit',
        );
        $budget = [
            'items' => 0,
            'bytes' => 0,
        ];

        return $this->redactValues(
            data: $data,
            redactedKeys: $redactedKeys,
            depth: 0,
            maximumDepth: $maximumDepth,
            maximumItems: $maximumItems,
            maximumStringBytes: $maximumStringBytes,
            maximumTotalBytes: $maximumTotalBytes,
            budget: $budget,
        );
    }

    /**
     * Redact nested values using case-insensitive key fragments.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $redactedKeys
     * @param  array{items: int, bytes: int}  $budget
     * @return array<string, mixed>
     */
    private function redactValues(
        array $data,
        array $redactedKeys,
        int $depth,
        int $maximumDepth,
        int $maximumItems,
        int $maximumStringBytes,
        int $maximumTotalBytes,
        array &$budget,
    ): array {
        $redacted = [];

        foreach ($data as $key => $value) {
            $budget['items']++;
            $budget['bytes'] += strlen((string) $key);

            if ($budget['items'] > $maximumItems) {
                throw new MailTrackingException(
                    'Mail notification metadata exceeds the configured item limit.',
                );
            }

            if (is_string($value) && strlen($value) > $maximumStringBytes) {
                throw new MailTrackingException(
                    'Mail notification metadata contains a string that exceeds the configured byte limit.',
                );
            }

            if (is_scalar($value)) {
                $budget['bytes'] += strlen((string) $value);
            }

            if ($budget['bytes'] > $maximumTotalBytes) {
                throw new MailTrackingException(
                    'Mail notification metadata exceeds the configured total byte limit.',
                );
            }

            $normalizedKey = $this->canonicalKey((string) $key);
            $isSensitive = collect($redactedKeys)->contains(
                static fn (string $sensitiveKey): bool => Str::contains(
                    $normalizedKey,
                    $sensitiveKey,
                ),
            );

            if ($isSensitive) {
                $redacted[(string) $key] = '[REDACTED]';

                continue;
            }

            $redacted[(string) $key] = match (true) {
                is_array($value) && $depth + 1 >= $maximumDepth => '[REDACTED]',
                is_array($value) => $this->redactValues(
                    data: $value,
                    redactedKeys: $redactedKeys,
                    depth: $depth + 1,
                    maximumDepth: $maximumDepth,
                    maximumItems: $maximumItems,
                    maximumStringBytes: $maximumStringBytes,
                    maximumTotalBytes: $maximumTotalBytes,
                    budget: $budget,
                ),
                is_scalar($value), $value === null => $value,
                default => '[REDACTED]',
            };
        }

        return $redacted;
    }

    /**
     * Normalize key spelling across snake, kebab, camel, and spaced variants.
     */
    private function canonicalKey(string $key): string
    {
        $canonical = preg_replace(
            '/[^\p{L}\p{N}]+/u',
            '',
            Str::lower(trim($key)),
        );

        return is_string($canonical) ? $canonical : '';
    }

    /**
     * Resolve one bounded integer privacy setting.
     */
    private function boundedInteger(
        string $key,
        int $default,
        int $minimum,
        int $maximum,
        string $label,
    ): int {
        $value = $this->config->get($key, $default);

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new MailTrackingException(sprintf(
                'Mail notification %s must be an integer between %d and %d.',
                $label,
                $minimum,
                $maximum,
            ));
        }

        return $value;
    }
}
