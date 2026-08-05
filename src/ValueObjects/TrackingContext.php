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
    public string $category;

    /**
     * Create a tracking context.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $category,
        public ?NotifiableReference $notifiable = null,
        public array $metadata = [],
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
        );
    }
}
