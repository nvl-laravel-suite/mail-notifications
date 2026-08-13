<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Stable pagination envelope for scheduled-mail administration.
 */
final readonly class ScheduledMailReadPage
{
    /**
     * @param  list<ScheduledMailListData>  $items
     */
    public function __construct(
        public array $items,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(
                static fn (ScheduledMailListData $item): array => $item->toArray(),
                $this->items,
            ),
            'meta' => [
                'current_page' => $this->currentPage,
                'last_page' => $this->lastPage,
                'per_page' => $this->perPage,
                'total' => $this->total,
            ],
        ];
    }
}
