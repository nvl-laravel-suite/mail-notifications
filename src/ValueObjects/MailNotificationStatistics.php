<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Stable aggregate delivery-health projection.
 */
final readonly class MailNotificationStatistics
{
    /**
     * @param  array<string, int>  $statuses
     * @param  list<MailNotificationReadData>  $recent
     */
    public function __construct(
        public int $total,
        public array $statuses,
        public int $accepted,
        public int $successful,
        public int $failed,
        public float $successRate,
        public float $failureRate,
        public array $recent,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'statuses' => $this->statuses,
            'accepted' => $this->accepted,
            'successful' => $this->successful,
            'failed' => $this->failed,
            'success_rate' => $this->successRate,
            'failure_rate' => $this->failureRate,
            'recent' => array_map(
                static fn (MailNotificationReadData $item): array => $item->toArray(),
                $this->recent,
            ),
        ];
    }
}
