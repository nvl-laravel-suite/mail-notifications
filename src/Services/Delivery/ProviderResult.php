<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

/** Retains internal provider submission, suppression and retry metadata for the delivery pipeline.
 * @internal
 */
class ProviderResult
{
    /**
     * @param  list<string>  $suppressedEmails
     * @param  array{status?: int, warnings?: list<string>, suppressed_emails?: list<string>}  $response
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = false,
        public readonly array $suppressedEmails = [],
        public readonly array $response = [],
        public readonly ?int $retryAfter = null,
    ) {}
}
