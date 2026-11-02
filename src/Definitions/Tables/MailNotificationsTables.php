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

    public const string Owners = 'nvl_mail_notifications_owners';

    public const string Integrations = 'nvl_mail_notifications_integrations';

    public const string ProviderAccounts = 'nvl_mail_notifications_provider_accounts';

    public const string SendingDomains = 'nvl_mail_notifications_sending_domains';

    public const string SenderIdentities = 'nvl_mail_notifications_sender_identities';

    public const string Clients = 'nvl_mail_notifications_clients';

    public const string OwnerMailSettings = 'nvl_mail_notifications_owner_mail_settings';

    public const string ClientMailSettings = 'nvl_mail_notifications_client_mail_settings';

    public const string ClientApiKeys = 'nvl_mail_notifications_client_api_keys';

    public const string Assets = 'nvl_mail_notifications_assets';

    public const string EmailMessages = 'nvl_mail_notifications_email_messages';

    public const string EmailRecipients = 'nvl_mail_notifications_email_recipients';

    public const string EmailAttempts = 'nvl_mail_notifications_email_attempts';

    public const string WebhookReceipts = 'nvl_mail_notifications_webhook_receipts';

    public const string EmailEvents = 'nvl_mail_notifications_email_events';

    public const string RecipientSuppressions = 'nvl_mail_notifications_recipient_suppressions';

    public const string MessageAssets = 'nvl_mail_notifications_message_assets';

    public const string EmailFlagRules = 'nvl_mail_notifications_email_flag_rules';

    public const string EmailMessageFlags = 'nvl_mail_notifications_email_message_flags';

    public const string ProviderSyncRuns = 'nvl_mail_notifications_provider_sync_runs';

    public const string ProviderSyncPages = 'nvl_mail_notifications_provider_sync_pages';

    public const string StagedMailFiles = 'nvl_mail_notifications_staged_mail_files';

    public const string EmailMessageReferences = 'nvl_mail_notifications_email_message_references';

    public const string ClientWebhookEndpoints = 'nvl_mail_notifications_client_webhook_endpoints';

    public const string ClientWebhookDeliveries = 'nvl_mail_notifications_client_webhook_deliveries';

    public const string MarketingOptOuts = 'nvl_mail_notifications_marketing_opt_outs';

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('mail-notifications', $key);
    }

    private function __construct() {}
}
