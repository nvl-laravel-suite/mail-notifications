<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

/**
 * Canonical column inventories required by the adoption boundary.
 *
 * @internal
 */
final class CanonicalMailNotificationSchema
{
    /** @return list<string> */
    public static function notifications(): array
    {
        return [
            'id', 'correlation_id', 'queue_reference', 'mailer', 'provider',
            'provider_message_id', 'status', 'message_category', 'subject',
            'from_email', 'from_name', 'to_recipients', 'cc_recipients',
            'bcc_recipients', 'primary_recipient_email', 'notifiable_type',
            'notifiable_id', 'metadata', 'accepted_at', 'delivered_at',
            'failed_at', 'status_changed_at', 'provider_occurred_at',
            'redacted_at', 'created_at', 'updated_at',
        ];
    }

    /** @return list<string> */
    public static function events(): array
    {
        return [
            'id', 'mail_notification_id', 'provider', 'provider_event_id',
            'provider_message_id', 'normalized_type', 'occurred_at', 'metadata',
            'processed_at', 'redacted_at', 'created_at', 'updated_at',
        ];
    }

    /** @return list<string> */
    public static function scheduled(): array
    {
        return [
            'id', 'factory_alias', 'payload_version', 'payload', 'to_recipients',
            'cc_recipients', 'bcc_recipients', 'status', 'scheduled_for',
            'available_at', 'attempts', 'max_attempts', 'last_attempt_at',
            'claim_token', 'locked_until', 'last_error', 'notifiable_type',
            'notifiable_id', 'metadata', 'sent_at', 'failed_at', 'cancelled_at',
            'redacted_at', 'created_at', 'updated_at',
        ];
    }
}
