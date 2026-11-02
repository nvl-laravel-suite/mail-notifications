<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use InvalidArgumentException;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\Support\Config\PackageStorage;

/** Resolves the opt-in delivery engine configuration and isolated storage names. */
final class ManagedConfiguration
{
    /**
     * Permit new prepared-content acceptance only while the package and capability are explicitly enabled.
     */
    public static function enabled(): bool
    {
        return config('nvl-mail-notifications.enabled', true) === true
            && config('nvl-mail-notifications.managed.enabled', false) === true;
    }

    /**
     * Permit queue dispatch and provider submission; pausing preserves persisted intents for later recovery.
     */
    public static function sendingEnabled(): bool
    {
        return self::enabled() && self::get('delivery.enabled', true) === true;
    }

    /**
     * Read the operational setting from the explicitly configured delivery namespace.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $namespace = config('nvl-mail-notifications.managed.configuration_namespace', 'nvl-mail-notifications.managed');

        if (! is_string($namespace) || trim($namespace) === '') {
            throw new InvalidArgumentException('The managed configuration namespace must be a non-empty string.');
        }

        return config($namespace.'.'.$key, $default);
    }

    /**
     * Require a string operational value instead of coercing malformed host configuration.
     */
    public static function string(string $key, string $default): string
    {
        $value = self::get($key, $default);
        if (! is_string($value)) {
            throw new InvalidArgumentException('The managed configuration ['.$key.'] must be a string.');
        }

        return $value;
    }

    /**
     * Read an integer operational value, accepting its documented integer-string environment form.
     */
    public static function integer(string $key, int $default): int
    {
        $value = self::get($key, $default);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($parsed)) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException('The managed configuration ['.$key.'] must be an integer.');
    }

    /**
     * Resolve a logical delivery table through the package's canonical table map.
     */
    public static function table(string $logical): string
    {
        return MailNotificationsTables::get($logical);
    }

    /**
     * Resolve the actual package database connection used for delivery writes and commit callbacks.
     */
    public static function connection(): ?string
    {
        return PackageStorage::connection('mail-notifications');
    }
}
