<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Nvl\MailNotifications\Exceptions\SensitiveStorageException;
use Nvl\MailNotifications\Services\SensitiveStorageCodec;

/**
 * Exposes the booted sensitive-storage codec to dependency-free Eloquent casts.
 */
final class SensitiveStorageBridge
{
    private static ?SensitiveStorageCodec $codec = null;

    /**
     * Install the application-scoped codec for model casts.
     */
    public static function use(SensitiveStorageCodec $codec): void
    {
        self::$codec = $codec;
    }

    /**
     * Forget the prior application codec before a new application boots.
     */
    public static function clear(): void
    {
        self::$codec = null;
    }

    /**
     * Return the booted codec or fail closed.
     */
    public static function codec(): SensitiveStorageCodec
    {
        if (! self::$codec instanceof SensitiveStorageCodec) {
            throw new SensitiveStorageException(
                'Mail notification sensitive storage has not been bootstrapped.',
            );
        }

        return self::$codec;
    }
}
