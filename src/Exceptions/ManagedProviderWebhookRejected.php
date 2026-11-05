<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

use DomainException;
use InvalidArgumentException;
use Nvl\Support\Contracts\PackageException;

/** Rejects a managed provider webhook without choosing an HTTP response.
 * @api
 */
final class ManagedProviderWebhookRejected extends DomainException implements PackageException
{
    /** @var 'unsupported_provider'|'payload_too_large'|'unsupported_content_type'|'invalid_signature'|'invalid_payload' */
    public readonly string $reason;

    public function __construct(string $reason, string $message)
    {
        if (! in_array($reason, ['unsupported_provider', 'payload_too_large', 'unsupported_content_type', 'invalid_signature', 'invalid_payload'], true)) {
            throw new InvalidArgumentException('The webhook rejection reason is unknown.');
        }
        $this->reason = $reason;
        parent::__construct($message);
    }
}
