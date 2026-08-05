<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Illuminate\Mail\SentMessage;

/**
 * Wraps a completed Laravel transport result for provider-capability resolution.
 */
final readonly class TransportResult
{
    /**
     * Create a transport result.
     */
    public function __construct(
        public string $mailer,
        public SentMessage $message,
    ) {}
}
