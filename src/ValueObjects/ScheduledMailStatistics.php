<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Privacy-bounded aggregate health for the scheduled-mail queue.
 */
final readonly class ScheduledMailStatistics
{
    /**
     * @param  array<string, int>  $statuses
     * @param  list<ScheduledMailListData>  $recent
     */
    public function __construct(
        public int $total,
        public array $statuses,
        public int $due,
        public array $recent,
    ) {}

    /**
     * @return array{total: int, statuses: array<string, int>, due: int, recent: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'statuses' => $this->statuses,
            'due' => $this->due,
            'recent' => array_map(
                static fn (ScheduledMailListData $message): array => $message->toArray(),
                $this->recent,
            ),
        ];
    }
}
