<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

use DomainException;

/**
 * Signals a verified provider event that matches multiple tracked deliveries.
 */
final class AmbiguousDeliveryEventException extends DomainException
{
    /**
     * Create the sanitized ambiguous-event signal.
     */
    public function __construct()
    {
        parent::__construct(
            'Multiple tracked mail notifications match the provider event.',
        );
    }
}
