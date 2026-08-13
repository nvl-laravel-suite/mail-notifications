<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;

/**
 * Validated, fixed-shape filters for scheduled-mail administration.
 */
final readonly class ScheduledMailReadQuery
{
    public ?string $factoryAlias;

    /** @var 'asc'|'desc' */
    public string $direction;

    public function __construct(
        public ?ScheduledMailStatus $status = null,
        ?string $factoryAlias = null,
        public ?NotifiableReference $notifiable = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public bool $dueOnly = false,
        public string $sort = 'scheduled_for',
        string $direction = 'asc',
        public int $page = 1,
        public int $perPage = 25,
    ) {
        $this->factoryAlias = $this->boundedString(
            $factoryAlias,
            128,
            'factory alias',
        );

        if (! in_array($sort, [
            'scheduled_for',
            'available_at',
            'created_at',
            'updated_at',
            'status',
            'attempts',
        ], true)) {
            throw new InvalidArgumentException('The scheduled mail sort is not allowed.');
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('The scheduled mail sort direction must be asc or desc.');
        }

        $this->direction = $direction;

        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Scheduled mail pagination values must be positive.');
        }

        if ($from instanceof CarbonImmutable
            && $to instanceof CarbonImmutable
            && $from->isAfter($to)) {
            throw new InvalidArgumentException('The scheduled mail date range is inverted.');
        }

        if ($dueOnly
            && $status instanceof ScheduledMailStatus
            && $status !== ScheduledMailStatus::Pending) {
            throw new InvalidArgumentException('Only pending scheduled mail can be filtered as due.');
        }
    }

    private function boundedString(?string $value, int $maximum, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);

        if (mb_strlen($normalized) > $maximum) {
            throw new InvalidArgumentException(
                "The scheduled mail {$label} must not exceed {$maximum} characters.",
            );
        }

        return $normalized;
    }
}
