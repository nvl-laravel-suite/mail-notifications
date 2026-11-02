<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

use DomainException;

/** Reports a managed delivery state conflict without selecting a transport response.
 * @api
 */
final class ManagedDeliveryConflict extends DomainException
{
    public function __construct(string $message = 'The idempotency key was already used for a different request.')
    {
        parent::__construct($message);
    }
}
