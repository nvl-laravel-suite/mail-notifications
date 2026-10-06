<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

use DomainException;
use Nvl\MailNotifications\Enums\MailNotificationsResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;

/**
 * Signals a verified provider event that matches multiple tracked deliveries.
 *
 * @api
 */
final class AmbiguousDeliveryEventException extends DomainException implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve safe package response metadata. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return new ExceptionResponse('mail-notifications', MailNotificationsResponseCode::AmbiguousDeliveryEvent, 409);
    }

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
