<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Serializes package-owned database timestamps in UTC with full precision.
 */
final class DatabaseTimestamp
{
    public const string FORMAT = 'Y-m-d H:i:s.u';

    /**
     * Normalize one timestamp for database writes and comparisons.
     */
    public static function format(DateTimeInterface $timestamp): string
    {
        return CarbonImmutable::instance($timestamp)
            ->setTimezone('UTC')
            ->format(self::FORMAT);
    }
}
