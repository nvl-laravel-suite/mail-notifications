<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

/**
 * Transforms serialized sensitive values into and out of host-owned storage protection.
 *
 * Implementations must emit self-identifying payloads, use the current key or
 * protection profile for writes, and retain previous keys or profiles for as
 * long as historical rows must remain readable. Restore failures must throw;
 * returning the unreadable payload as plaintext is forbidden.
 */
interface SensitiveDataTransformer
{
    /**
     * Protect one serialized value for durable storage.
     */
    public function transform(string $scope, string $plaintext): string;

    /**
     * Restore one protected value using current or retained historical keys.
     */
    public function restore(string $scope, string $transformed): string;
}
