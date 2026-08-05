<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Represents an authenticated provider webhook payload.
 */
final readonly class VerifiedWebhook
{
    /**
     * Create a verified provider webhook.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $provider,
        public array $payload,
    ) {}
}
