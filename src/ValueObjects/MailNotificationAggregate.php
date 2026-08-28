<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/** One bounded aggregate dimension in a mail notification statistics projection. */
final readonly class MailNotificationAggregate
{
    /** Create one stable aggregate key and count. */
    public function __construct(
        public string $key,
        public int $count,
    ) {}

    /**
     * @return array{key: string, count: int}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'count' => $this->count,
        ];
    }
}
