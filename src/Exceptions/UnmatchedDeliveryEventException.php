<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

use DomainException;
use Nvl\MailNotifications\Enums\MailNotificationsResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;

/**
 * Signals a verified provider event whose tracked message may not be visible yet.
 *
 * @api
 */
final class UnmatchedDeliveryEventException extends DomainException implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve safe package response metadata. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return new ExceptionResponse('mail-notifications', MailNotificationsResponseCode::UnmatchedDeliveryEvent, 409);
    }

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
