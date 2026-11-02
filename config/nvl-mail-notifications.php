<?php

declare(strict_types=1);

use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\ConfiguredMailNotificationReadAuthorization;
use Nvl\MailNotifications\Services\ConfiguredScheduledMailReadAuthorization;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\Services\DefaultSensitiveDataRedactor;

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
    'managed' => [
        'enabled' => false,
        'configuration_namespace' => 'nvl-mail-notifications.managed',
        'delivery' => [
            'enabled' => (bool) env('MAIL_DELIVERY_ENABLED', true),
        ],
        'content' => [
            'max_html_bytes' => (int) env('MAIL_CONTENT_MAX_HTML_BYTES', 10 * 1024 * 1024),
        ],
        'webhooks' => [
            'connection' => env('MAIL_WEBHOOK_QUEUE_CONNECTION'),
            'queue' => env('MAIL_WEBHOOK_QUEUE', 'webhooks'),
            'local_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('MAIL_WEBHOOK_LOCAL_ORIGINS', ''))))),
            'local_environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('MAIL_WEBHOOK_LOCAL_ENVIRONMENTS', 'local,testing'))))),
        ],
        'authentication' => [
            'requests_per_minute' => env('MAIL_AUTH_REQUESTS_PER_MINUTE', 600),
        ],
        'storage' => [
            'disk' => env('MAIL_STORAGE_DISK', 'local'),
            'public_disk' => env('MAIL_STORAGE_PUBLIC_DISK', 'public'),
            'orphan_grace_hours' => (int) env('MAIL_ORPHAN_GRACE_HOURS', 24),
        ],
        'attachments' => [
            'retention_hours' => (int) env('MAIL_ATTACHMENT_RETENTION_HOURS', 72),
        ],
        'maintenance' => [
            'batch_size' => (int) env('MAIL_MAINTENANCE_BATCH_SIZE', 1000),
            'max_seconds' => (int) env('MAIL_MAINTENANCE_MAX_SECONDS', 30),
        ],
        'sending_limits' => [
            'providers' => [
                'mailersend' => (int) env('MAIL_MAILERSEND_PER_MINUTE', 120),
                'sendgrid' => (int) env('MAIL_SENDGRID_PER_MINUTE', 60),
                'mailgun' => (int) env('MAIL_MAILGUN_PER_MINUTE', 60),
                'smtp' => (int) env('MAIL_SMTP_PER_MINUTE', 60),
            ],
            'accounts' => [],
        ],
        'payload_limits' => [
            'mailersend' => 35000000,
            'sendgrid' => 20000000,
            'mailgun' => 25000000,
            'smtp' => 25000000,
        ],
    ],
    'models' => [
        'notification' => MailNotification::class,
        'scheduled_message' => ScheduledMailMessage::class,
    ],

    'ownership' => ['scoped' => true],
    'enabled' => env('NVL_MAIL_NOTIFICATIONS_ENABLED', true),

    'tracking' => [
        'enabled' => env('NVL_MAIL_NOTIFICATIONS_TRACKING_ENABLED', true),
        'failure_policy' => env('NVL_MAIL_NOTIFICATIONS_FAILURE_POLICY', 'fail_closed'),
        'excluded_mailers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NVL_MAIL_NOTIFICATIONS_EXCLUDED_MAILERS', '')),
        ))),
        'store_subject' => env('NVL_MAIL_NOTIFICATIONS_STORE_SUBJECT', true),
    ],

    'testing' => [
        'enabled' => env('NVL_MAIL_NOTIFICATIONS_TESTING_ENABLED', false),
        'to_address' => env('NVL_MAIL_NOTIFICATIONS_TESTING_TO_ADDRESS'),
        'to_name' => env('NVL_MAIL_NOTIFICATIONS_TESTING_TO_NAME', 'Mail Test Inbox'),
        'respect_environment' => env('NVL_MAIL_NOTIFICATIONS_TESTING_RESPECT_ENV', true),
        'environments' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NVL_MAIL_NOTIFICATIONS_TESTING_ENVIRONMENTS', 'local,testing,staging')),
        ))),
    ],

    'providers' => [
        'default' => env('NVL_MAIL_NOTIFICATIONS_PROVIDER'),
        'mailers' => [],
        'mailersend' => [
            'mailers' => ['mailersend'],
            'signing_secret' => env('NVL_MAIL_NOTIFICATIONS_MAILERSEND_SIGNING_SECRET',
            ),
            'validation_secret' => 'test_Am3L1GuOIc4blLUuHqAPxxwkZaJyEk8G',
            'signature_headers' => ['signature'],
            'message_id_headers' => [
                'x-mailersend-message-id',
                'x-message-id',
            ],
            'timestamp_bounds' => [
                'maximum_past_age_seconds' => 604_800,
                'maximum_future_skew_seconds' => 300,
            ],
            'management' => [
                'enabled' => env('NVL_MAIL_NOTIFICATIONS_MAILERSEND_MANAGEMENT_ENABLED',
                    false,
                ),
                'token' => env('NVL_MAIL_NOTIFICATIONS_MAILERSEND_API_TOKEN',
                ),
                'domain_id' => env('NVL_MAIL_NOTIFICATIONS_MAILERSEND_DOMAIN_ID',
                ),
                'api_url' => env('NVL_MAIL_NOTIFICATIONS_MAILERSEND_API_URL',
                    'https://api.mailersend.com/v1',
                ),
                'timeout_seconds' => (int) env('NVL_MAIL_NOTIFICATIONS_MAILERSEND_TIMEOUT_SECONDS',
                    10,
                ),
                'connect_timeout_seconds' => (int) env('NVL_MAIL_NOTIFICATIONS_MAILERSEND_CONNECT_TIMEOUT_SECONDS',
                    3,
                ),
                'pagination' => [
                    'page_size' => 100,
                    'max_pages' => 10,
                ],
                'webhook' => [
                    'name' => env('NVL_MAIL_NOTIFICATIONS_MAILERSEND_WEBHOOK_NAME',
                        'Mail Notifications',
                    ),
                    'url' => env('NVL_MAIL_NOTIFICATIONS_MAILERSEND_WEBHOOK_URL',
                    ),
                    'events' => [
                        'activity.sent',
                        'activity.delivered',
                        'activity.deferred',
                        'activity.opened',
                        'activity.opened_unique',
                        'activity.clicked',
                        'activity.clicked_unique',
                        'activity.soft_bounced',
                        'activity.hard_bounced',
                        'activity.unsubscribed',
                        'activity.spam_complaint',
                    ],
                    'enabled' => true,
                    'version' => 2,
                ],
            ],
        ],
    ],

    'notifiable_types' => [],

    'management' => [
        'maximum_per_page' => 100,
        'scheduled_maximum_per_page' => 100,
        'suggestion_limit' => 20,
        'authorization' => [
            'class' => ConfiguredMailNotificationReadAuthorization::class,
            'callback' => null,
        ],
        'scheduled_authorization' => [
            'class' => ConfiguredScheduledMailReadAuthorization::class,
            'callback' => null,
        ],
    ],

    'adoption' => [
        'maximum_manifest_bytes' => 1_048_576,
        'maximum_records' => 10_000,
    ],

    'extensions' => [
        'provider_adapters' => [],
        'message_id_resolvers' => [],
        'notifiable_type_providers' => [],
        'scheduled_message_factories' => [],
        'webhook_managers' => [],
    ],

    'services' => [
        'tracking_lifecycle' => DatabaseTrackingLifecycle::class,
        'sensitive_data_redactor' => DefaultSensitiveDataRedactor::class,
        'sensitive_storage_transformer' => null,
    ],

    'webhooks' => [
        'enabled' => env('NVL_MAIL_NOTIFICATIONS_WEBHOOKS_ENABLED', true),
        'allowed_content_types' => [
            'application/json',
        ],
        'unknown_event_policy' => env('NVL_MAIL_NOTIFICATIONS_WEBHOOK_UNKNOWN_EVENT_POLICY',
            'acknowledge',
        ),
        'unmatched_events' => [
            'policy' => env('NVL_MAIL_NOTIFICATIONS_WEBHOOK_UNMATCHED_EVENT_POLICY',
                'retry_then_acknowledge',
            ),
            'retry_grace_seconds' => (int) env('NVL_MAIL_NOTIFICATIONS_WEBHOOK_UNMATCHED_RETRY_GRACE_SECONDS',
                300,
            ),
        ],
        'max_payload_bytes' => (int) env('NVL_MAIL_NOTIFICATIONS_WEBHOOK_MAX_PAYLOAD_BYTES',
            1_048_576,
        ),
    ],

    'integrations' => ['settings' => null],

    'scheduling' => [
        'enabled' => env('NVL_MAIL_NOTIFICATIONS_SCHEDULING_ENABLED', false),
        'delivery_profile' => null,
        'delivery_profile_setting' => null,
        'allowed_delivery_profiles' => [],
        'batch_size' => (int) env('NVL_MAIL_NOTIFICATIONS_SCHEDULING_BATCH_SIZE',
            50,
        ),
        'claim_ttl_seconds' => (int) env('NVL_MAIL_NOTIFICATIONS_SCHEDULING_CLAIM_TTL_SECONDS',
            300,
        ),
        'max_attempts' => (int) env('NVL_MAIL_NOTIFICATIONS_SCHEDULING_MAX_ATTEMPTS',
            3,
        ),
        'backoff_seconds' => [60, 300, 900],
        'max_payload_bytes' => (int) env('NVL_MAIL_NOTIFICATIONS_SCHEDULING_MAX_PAYLOAD_BYTES',
            65_536,
        ),
        'max_recipients' => (int) env('NVL_MAIL_NOTIFICATIONS_SCHEDULING_MAX_RECIPIENTS',
            1_000,
        ),
    ],

    'retention' => [
        'notifications' => [
            'days' => (int) env('NVL_MAIL_NOTIFICATIONS_RETENTION_DAYS',
                365,
            ),
            'statuses' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('NVL_MAIL_NOTIFICATIONS_RETENTION_STATUSES',
                    'delivered,opened,clicked,bounced,complained,rejected,failed,unsubscribed',
                )),
            ))),
        ],
        'scheduled_messages' => [
            'enabled' => env('NVL_MAIL_NOTIFICATIONS_SCHEDULED_RETENTION_ENABLED',
                false,
            ),
            'days' => (int) env('NVL_MAIL_NOTIFICATIONS_SCHEDULED_RETENTION_DAYS',
                90,
            ),
            'statuses' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('NVL_MAIL_NOTIFICATIONS_SCHEDULED_RETENTION_STATUSES',
                    'sent,failed,cancelled',
                )),
            ))),
        ],
        'batch_size' => (int) env('NVL_MAIL_NOTIFICATIONS_PRUNE_BATCH_SIZE',
            500,
        ),
        'limit' => (int) env('NVL_MAIL_NOTIFICATIONS_PRUNE_LIMIT',
            5_000,
        ),
        'anonymization' => [
            'enabled' => env('NVL_MAIL_NOTIFICATIONS_ANONYMIZATION_ENABLED',
                false,
            ),
            'notifications' => [
                'days' => (int) env('NVL_MAIL_NOTIFICATIONS_ANONYMIZATION_DAYS',
                    180,
                ),
                'statuses' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('NVL_MAIL_NOTIFICATIONS_ANONYMIZATION_STATUSES',
                        'delivered,opened,clicked,bounced,complained,rejected,failed,unsubscribed',
                    )),
                ))),
            ],
            'scheduled_messages' => [
                'enabled' => env('NVL_MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_ENABLED',
                    false,
                ),
                'days' => (int) env('NVL_MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_DAYS',
                    90,
                ),
                'statuses' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('NVL_MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_STATUSES',
                        'sent,failed,cancelled',
                    )),
                ))),
            ],
            'batch_size' => (int) env('NVL_MAIL_NOTIFICATIONS_ANONYMIZATION_BATCH_SIZE',
                500,
            ),
            'limit' => (int) env('NVL_MAIL_NOTIFICATIONS_ANONYMIZATION_LIMIT',
                5_000,
            ),
        ],
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'privacy' => [
        'max_depth' => (int) env('NVL_MAIL_NOTIFICATIONS_METADATA_MAX_DEPTH',
            16,
        ),
        'max_items' => (int) env('NVL_MAIL_NOTIFICATIONS_METADATA_MAX_ITEMS',
            1_000,
        ),
        'max_string_bytes' => (int) env('NVL_MAIL_NOTIFICATIONS_METADATA_MAX_STRING_BYTES',
            16_384,
        ),
        'max_total_bytes' => (int) env('NVL_MAIL_NOTIFICATIONS_METADATA_MAX_TOTAL_BYTES',
            65_536,
        ),
        'sensitive_storage' => [
            'enabled' => env('NVL_MAIL_NOTIFICATIONS_SENSITIVE_STORAGE_ENABLED',
                false,
            ),
            'max_transformed_bytes' => (int) env('NVL_MAIL_NOTIFICATIONS_SENSITIVE_STORAGE_MAX_TRANSFORMED_BYTES',
                262_144,
            ),
        ],
        'redacted_keys' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NVL_MAIL_NOTIFICATIONS_REDACTED_KEYS',
                'authorization,cookie,password,token,secret,signature,api_key,two_factor_code,verification_code,magic_link,otp',
            )),
        ))),
    ],
    'connection' => env('NVL_MAIL_NOTIFICATIONS_DB_CONNECTION'),
    'tables' => ['notifications' => env('NVL_MAIL_NOTIFICATIONS_TABLE',
        MailNotificationsTables::Notifications,
    ), 'events' => env('NVL_MAIL_NOTIFICATIONS_EVENTS_TABLE',
        MailNotificationsTables::Events,
    ), 'scheduled_messages' => env('NVL_MAIL_NOTIFICATIONS_SCHEDULED_MESSAGES_TABLE',
        MailNotificationsTables::ScheduledMessages,
    ),
        'owners' => MailNotificationsTables::Owners,
        'integrations' => MailNotificationsTables::Integrations,
        'provider_accounts' => MailNotificationsTables::ProviderAccounts,
        'sending_domains' => MailNotificationsTables::SendingDomains,
        'sender_identities' => MailNotificationsTables::SenderIdentities,
        'clients' => MailNotificationsTables::Clients,
        'owner_mail_settings' => MailNotificationsTables::OwnerMailSettings,
        'client_mail_settings' => MailNotificationsTables::ClientMailSettings,
        'client_api_keys' => MailNotificationsTables::ClientApiKeys,
        'assets' => MailNotificationsTables::Assets,
        'email_messages' => MailNotificationsTables::EmailMessages,
        'email_recipients' => MailNotificationsTables::EmailRecipients,
        'email_attempts' => MailNotificationsTables::EmailAttempts,
        'webhook_receipts' => MailNotificationsTables::WebhookReceipts,
        'email_events' => MailNotificationsTables::EmailEvents,
        'recipient_suppressions' => MailNotificationsTables::RecipientSuppressions,
        'message_assets' => MailNotificationsTables::MessageAssets,
        'email_flag_rules' => MailNotificationsTables::EmailFlagRules,
        'email_message_flags' => MailNotificationsTables::EmailMessageFlags,
        'provider_sync_runs' => MailNotificationsTables::ProviderSyncRuns,
        'provider_sync_pages' => MailNotificationsTables::ProviderSyncPages,
        'staged_mail_files' => MailNotificationsTables::StagedMailFiles,
        'email_message_references' => MailNotificationsTables::EmailMessageReferences,
        'client_webhook_endpoints' => MailNotificationsTables::ClientWebhookEndpoints,
        'client_webhook_deliveries' => MailNotificationsTables::ClientWebhookDeliveries,
        'marketing_opt_outs' => MailNotificationsTables::MarketingOptOuts,
    ],

];
