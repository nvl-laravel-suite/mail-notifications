<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Describes one non-mutating package readiness check.
 */
final readonly class MailNotificationsDoctorCheck
{
    /**
     * Create a package readiness check.
     */
    public function __construct(
        public string $key,
        public string $severity,
        public bool $passed,
        public string $message,
    ) {}
}
