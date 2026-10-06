<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

use Nvl\MailNotifications\Enums\MailNotificationsResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use RuntimeException;

/** Base public MailNotifications runtime failure.
 * @api
 */
class MailNotificationsException extends RuntimeException implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve the declared safe failure for this native hierarchy. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return match (static::class) {
            MailDeliveryCancelled::class => new ExceptionResponse('mail-notifications', MailNotificationsResponseCode::MailDeliveryCancelled, 409),
            MailRetentionException::class => new ExceptionResponse('mail-notifications', MailNotificationsResponseCode::InvalidRetentionConfiguration, 500),
            MailTrackingException::class => new ExceptionResponse('mail-notifications', MailNotificationsResponseCode::MailTrackingFailed, 500),
            ScheduledMailException::class => new ExceptionResponse('mail-notifications', MailNotificationsResponseCode::ScheduledMailFailed, 422),
            SensitiveStorageException::class => new ExceptionResponse('mail-notifications', MailNotificationsResponseCode::SensitiveStorageUnavailable, 500),
            UnreadableSensitiveDataException::class => new ExceptionResponse('mail-notifications', MailNotificationsResponseCode::SensitiveDataUnreadable, 500),
            default => new ExceptionResponse('mail-notifications', MailNotificationsResponseCode::OperationFailed),
        };
    }
}
