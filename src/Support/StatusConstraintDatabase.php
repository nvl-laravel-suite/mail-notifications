<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Illuminate\Database\Connection;
use LogicException;

/**
 * Verifies that a database can enforce the package's exact status allowlists.
 */
final class StatusConstraintDatabase
{
    /**
     * Fail before schema mutation on a database without enforceable checks.
     */
    public static function assertSupported(Connection $connection): void
    {
        $reason = self::unsupportedReason($connection);

        if ($reason !== null) {
            throw new LogicException($reason);
        }
    }

    /**
     * Return a precise incompatibility reason, or null when supported.
     */
    public static function unsupportedReason(
        Connection $connection,
    ): ?string {
        $driver = $connection->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            return null;
        }

        return sprintf(
            'Database driver [%s] cannot enforce mail notification status invariants.',
            $driver,
        );
    }
}
