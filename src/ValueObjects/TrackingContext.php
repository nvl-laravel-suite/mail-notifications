<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\MailTrackable;

/**
 * Carries serializable, provider-neutral metadata for one tracked message.
 */
final readonly class TrackingContext
{
    /** @var list<string> */
    private const array FORBIDDEN_CORRELATION_KEY_FRAGMENTS = [
        'email',
        'token',
        'secret',
        'password',
        'payload',
    ];

    public string $category;

    /** @var array<string, string|int|bool|null> */
    public array $correlation;

    /**
     * Create a tracking context.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $correlation
     */
    public function __construct(
        string $category,
        public ?NotifiableReference $notifiable = null,
        public array $metadata = [],
        array $correlation = [],
    ) {
        $normalizedCategory = trim($category);

        if ($normalizedCategory === '') {
            throw new InvalidArgumentException('A tracked message category cannot be empty.');
        }

        if (mb_strlen($normalizedCategory) > 128) {
            throw new InvalidArgumentException(
                'A tracked message category may not exceed 128 characters.',
            );
        }

        $this->category = $normalizedCategory;
        $this->correlation = self::normalizeCorrelation($correlation);
    }

    /**
     * Create a context for one stable message category.
     */
    public static function forCategory(string $category): self
    {
        return new self($category);
    }

    /**
     * Return a copy associated with a host-owned notifiable model.
     */
    public function forNotifiable(MailTrackable $notifiable): self
    {
        return new self(
            category: $this->category,
            notifiable: NotifiableReference::fromTrackable($notifiable),
            metadata: $this->metadata,
            correlation: $this->correlation,
        );
    }

    /**
     * Return a copy with additional safe correlation metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            category: $this->category,
            notifiable: $this->notifiable,
            metadata: array_replace($this->metadata, $metadata),
            correlation: $this->correlation,
        );
    }

    /**
     * Return a copy with additional validated correlation identifiers.
     *
     * @param  array<string, mixed>  $correlation
     */
    public function withCorrelation(array $correlation): self
    {
        return new self(
            category: $this->category,
            notifiable: $this->notifiable,
            metadata: $this->metadata,
            correlation: array_replace($this->correlation, $correlation),
        );
    }

    /**
     * Validate the event-safe scalar correlation boundary.
     *
     * @param  array<array-key, mixed>  $correlation
     * @return array<string, string|int|bool|null>
     */
    private static function normalizeCorrelation(array $correlation): array
    {
        if (count($correlation) > 20) {
            throw new InvalidArgumentException('Tracking correlation may contain at most 20 keys.');
        }

        $normalized = [];

        foreach ($correlation as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
                throw new InvalidArgumentException('Tracking correlation keys must use bounded snake case.');
            }

            foreach (self::FORBIDDEN_CORRELATION_KEY_FRAGMENTS as $fragment) {
                if (str_contains($key, $fragment)) {
                    throw new InvalidArgumentException('Tracking correlation keys may not describe sensitive data.');
                }
            }

            if (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null) {
                throw new InvalidArgumentException('Tracking correlation values must be scalar identifiers or null.');
            }

            if (is_string($value)) {
                if (! mb_check_encoding($value, 'UTF-8')) {
                    throw new InvalidArgumentException('Tracking correlation strings must contain valid UTF-8.');
                }

                if (mb_strlen($value) > 255) {
                    throw new InvalidArgumentException('Tracking correlation strings may not exceed 255 characters.');
                }
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
