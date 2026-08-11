<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Definitions\Tables;

/**
 * Defines the canonical table names owned by the Mail Notifications package.
 */
final class MailNotificationsTables
{
    public const string Notifications = 'mail_notifications';

    public const string Events = 'mail_notification_events';

    public const string ScheduledMessages = 'scheduled_mail_messages';

    private function __construct() {}
}
