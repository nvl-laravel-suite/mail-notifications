<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use LogicException;
use Throwable;

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

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return sprintf(
                'Database driver [%s] cannot enforce mail notification status invariants.',
                $driver,
            );
        }

        if ($driver === 'mysql') {
            if ($connection instanceof MySqlConnection
                && $connection->isMaria()) {
                return 'A MariaDB server must use Laravel\'s [mariadb] connection driver so mail notification status metadata is inspected safely.';
            }

            try {
                $enforcedColumnCount = $connection->scalar(
                    <<<'SQL'
                        select count(*)
                        from information_schema.columns
                        where upper(table_schema) = 'INFORMATION_SCHEMA'
                          and upper(table_name) = 'TABLE_CONSTRAINTS'
                          and upper(column_name) = 'ENFORCED'
                        SQL,
                );
            } catch (Throwable) {
                return 'MySQL CHECK enforcement capability could not be verified from INFORMATION_SCHEMA.TABLE_CONSTRAINTS.ENFORCED.';
            }

            if (is_numeric($enforcedColumnCount)
                && (int) $enforcedColumnCount > 0) {
                return null;
            }

            $serverVersion = trim($connection->getServerVersion());

            return sprintf(
                'MySQL [%s] cannot expose enforced package status invariants; MySQL 8.0.16 or newer is required.',
                $serverVersion !== '' ? $serverVersion : 'unknown',
            );
        }

        $serverVersion = trim($connection->getServerVersion());
        $minimumVersion = '10.3.0';
        $normalizedVersion = self::normalizedVersion($serverVersion);

        if ($normalizedVersion === null
            || version_compare(
                $normalizedVersion,
                $minimumVersion,
                '<',
            )) {
            return sprintf(
                'MariaDB [%s] cannot enforce the package status invariants; MariaDB %s or newer is required.',
                $serverVersion !== '' ? $serverVersion : 'unknown',
                $minimumVersion,
            );
        }

        try {
            $constraintChecks = $connection->scalar(
                'select @@session.check_constraint_checks',
            );
        } catch (Throwable) {
            return 'MariaDB CHECK enforcement state could not be verified from @@session.check_constraint_checks.';
        }

        if (! in_array(
            $constraintChecks,
            [true, 1, '1', 'ON', 'on'],
            true,
        )) {
            return 'MariaDB session variable [check_constraint_checks] must be enabled for mail notification status invariants.';
        }

        return null;
    }

    /**
     * Extract a comparable semantic version from a server version string.
     */
    private static function normalizedVersion(string $serverVersion): ?string
    {
        if (preg_match(
            '/(?<!\d)(\d+\.\d+\.\d+)(?!\d)/',
            $serverVersion,
            $matches,
        ) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
