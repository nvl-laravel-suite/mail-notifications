<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Describes one new scheduled delivery using host-owned message semantics.
 */
final readonly class ScheduleMailData
{
    public string $factoryAlias;

    /**
     * Intended recipient delivery instant normalized to UTC.
     */
    public CarbonImmutable $scheduledFor;

    /**
     * Initial package submission eligibility normalized to UTC.
     */
    public CarbonImmutable $availableAt;

    /**
     * Create a scheduling request.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $factoryAlias,
        public int $payloadVersion,
        public array $payload,
        public ScheduledRecipients $recipients,
        CarbonImmutable $scheduledFor,
        public ?NotifiableReference $notifiable = null,
        public array $metadata = [],
        public ?int $maxAttempts = null,
        ?CarbonImmutable $availableAt = null,
    ) {
        $alias = trim($factoryAlias);
        $scheduledFor = $scheduledFor->setTimezone('UTC');
        $availableAt = ($availableAt ?? $scheduledFor)->setTimezone('UTC');

        if ($alias === '' || mb_strlen($alias) > 128) {
            throw new InvalidArgumentException(
                'Scheduled message aliases must contain 1 to 128 characters.',
            );
        }

        if ($payloadVersion < 1) {
            throw new InvalidArgumentException(
                'Scheduled message payload versions must be positive integers.',
            );
        }

        if ($maxAttempts !== null && ($maxAttempts < 1 || $maxAttempts > 100)) {
            throw new InvalidArgumentException(
                'Scheduled mail max attempts must be between 1 and 100.',
            );
        }

        if ($availableAt->greaterThan($scheduledFor)) {
            throw new InvalidArgumentException(
                'Scheduled mail availability must be at or before its scheduled delivery time.',
            );
        }

        $this->factoryAlias = $alias;
        $this->scheduledFor = $scheduledFor;
        $this->availableAt = $availableAt;
    }
}
