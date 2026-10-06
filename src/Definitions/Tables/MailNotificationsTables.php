<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/**
 * Defines the canonical table names owned by the Mail Notifications package.
 */
final class MailNotificationsTables
{
    public const string Notifications = 'nvl_mail_notifications_notifications';

    public const string Events = 'nvl_mail_notifications_events';

    public const string ScheduledMessages = 'nvl_mail_notifications_scheduled_messages';

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('mail-notifications', $key);
    }

    private function __construct() {}
}
