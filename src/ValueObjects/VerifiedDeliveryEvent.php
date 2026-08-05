<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;

/**
 * Represents one authenticated and normalized provider delivery event.
 */
final readonly class VerifiedDeliveryEvent
{
    public string $provider;

    public string $eventId;

    public ?string $providerMessageId;

    public ?string $correlationId;

    /**
     * Create a verified delivery event.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $provider,
        string $eventId,
        public MailDeliveryStatus $status,
        public CarbonImmutable $occurredAt,
        ?string $providerMessageId = null,
        ?string $correlationId = null,
        public array $metadata = [],
    ) {
        $normalizedProvider = trim($provider);
        $normalizedEventId = trim($eventId);
        $normalizedProviderMessageId = $providerMessageId !== null
            ? trim($providerMessageId)
            : null;
        $normalizedCorrelationId = $correlationId !== null
            ? trim($correlationId)
            : null;

        if ($normalizedProvider === '' || $normalizedEventId === '') {
            throw new InvalidArgumentException(
                'Verified provider events require a provider and event identifier.',
            );
        }

        if (mb_strlen($normalizedProvider) > 128
            || mb_strlen($normalizedEventId) > 255
            || ($normalizedProviderMessageId !== null
                && mb_strlen($normalizedProviderMessageId) > 255)) {
            throw new InvalidArgumentException(
                'Verified provider event identifiers exceed package storage limits.',
            );
        }

        if (($providerMessageId !== null && $normalizedProviderMessageId === '')
            || ($correlationId !== null && $normalizedCorrelationId === '')) {
            throw new InvalidArgumentException(
                'Provider message and correlation identifiers cannot be blank.',
            );
        }

        if ($normalizedProviderMessageId === null && $normalizedCorrelationId === null) {
            throw new InvalidArgumentException(
                'Verified provider events require a message or correlation identifier.',
            );
        }

        if ($normalizedCorrelationId !== null && ! Str::isUuid($normalizedCorrelationId)) {
            throw new InvalidArgumentException(
                'Verified provider event correlation identifiers must be UUIDs.',
            );
        }

        $this->provider = $normalizedProvider;
        $this->eventId = $normalizedEventId;
        $this->providerMessageId = $normalizedProviderMessageId;
        $this->correlationId = $normalizedCorrelationId;
    }
}
