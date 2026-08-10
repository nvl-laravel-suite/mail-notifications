<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;

/**
 * Validated, fixed-shape filters for administrative delivery-history reads.
 */
final readonly class MailNotificationReadQuery
{
    public ?string $search;

    public ?string $mailer;

    public ?string $messageCategory;

    /** @var 'asc'|'desc' */
    public string $direction;

    /**
     * Create one bounded management query.
     */
    public function __construct(
        ?string $search = null,
        public ?MailDeliveryStatus $status = null,
        ?string $mailer = null,
        ?string $messageCategory = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public bool $acceptedOnly = false,
        public bool $failedOnly = false,
        public string $sort = 'created_at',
        string $direction = 'desc',
        public int $page = 1,
        public int $perPage = 25,
    ) {
        $this->search = $this->boundedString($search, 160, 'search');
        $this->mailer = $this->boundedString($mailer, 128, 'mailer');
        $this->messageCategory = $this->boundedString(
            $messageCategory,
            128,
            'message category',
        );

        if (! in_array($sort, [
            'created_at',
            'updated_at',
            'status_changed_at',
            'accepted_at',
            'delivered_at',
            'failed_at',
            'status',
            'mailer',
            'message_category',
        ], true)) {
            throw new InvalidArgumentException('The mail notification sort is not allowed.');
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('The mail notification sort direction must be asc or desc.');
        }

        $this->direction = $direction;

        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Mail notification pagination values must be positive.');
        }

        if ($from instanceof CarbonImmutable
            && $to instanceof CarbonImmutable
            && $from->isAfter($to)) {
            throw new InvalidArgumentException('The mail notification date range is inverted.');
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
                "The mail notification {$label} must not exceed {$maximum} characters.",
            );
        }

        return $normalized;
    }
}
