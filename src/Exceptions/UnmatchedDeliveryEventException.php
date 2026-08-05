<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

use DomainException;

/**
 * Signals a verified provider event whose tracked message may not be visible yet.
 */
final class UnmatchedDeliveryEventException extends DomainException
{
    /**
     * Create the sanitized unmatched-event signal.
     */
    public function __construct()
    {
        parent::__construct(
            'No tracked mail notification matches the provider event.',
        );
    }
}
