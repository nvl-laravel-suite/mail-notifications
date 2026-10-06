<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

use Nvl\Support\Contracts\ResponseCode;

/** Stable public response discriminators for MailNotifications.
 * @api
 */
enum MailNotificationsResponseCode: string implements ResponseCode
{
    case OperationFailed = 'operation_failed';
    case MailDeliveryCancelled = 'mail_delivery_cancelled';
    case InvalidRetentionConfiguration = 'invalid_retention_configuration';
    case MailTrackingFailed = 'mail_tracking_failed';
    case ScheduledMailFailed = 'scheduled_mail_failed';
    case SensitiveStorageUnavailable = 'sensitive_storage_unavailable';
    case SensitiveDataUnreadable = 'sensitive_data_unreadable';
    case AmbiguousDeliveryEvent = 'ambiguous_delivery_event';
    case UnmatchedDeliveryEvent = 'unmatched_delivery_event';
}
