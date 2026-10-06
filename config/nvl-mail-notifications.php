<?php

declare(strict_types=1);

use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Services\ConfiguredMailNotificationReadAuthorization;
use Nvl\MailNotifications\Services\ConfiguredScheduledMailReadAuthorization;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\Services\DefaultSensitiveDataRedactor;
use Nvl\Support\Config\PackageEnvironment;

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
    'enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_ENABLED', true),

    'tenancy' => [
        'active_tenant_worklist' => [],
    ],

    'tracking' => [
        'enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_TRACKING_ENABLED', true),
        'failure_policy' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_FAILURE_POLICY', 'fail_closed'),
        'excluded_mailers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_EXCLUDED_MAILERS', '')),
        ))),
        'store_subject' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_STORE_SUBJECT', true),
    ],

    'presentation' => [
        'global_markdown' => false,
        'global_view_data' => false,
        'enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_PRESENTATION_ENABLED', true),
        'auto_load' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_PRESENTATION_AUTO_LOAD', true),
        'brand' => [
            'header_enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_BRAND_HEADER_ENABLED', true),
            'footer_enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_BRAND_FOOTER_ENABLED', true),
            'name' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_BRAND_NAME'),
            'url' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_BRAND_URL'),
            'logo_url' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_BRAND_LOGO_URL'),
            'logo_alt' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_BRAND_LOGO_ALT'),
            'support_text' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_BRAND_SUPPORT_TEXT'),
            'footer_text' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_BRAND_FOOTER_TEXT'),
        ],
        'tokens' => [
            'font_family' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif",
            'canvas' => '#f6f8fb',
            'surface' => '#ffffff',
            'text' => '#4b5563',
            'heading' => '#111827',
            'muted' => '#6b7280',
            'primary' => '#2563eb',
            'primary_hover' => '#1d4ed8',
            'primary_soft' => '#eff6ff',
            'accent' => '#7c3aed',
            'border' => '#e5e7eb',
            'info' => '#2563eb',
            'info_soft' => '#eff6ff',
            'success' => '#15803d',
            'success_soft' => '#f0fdf4',
            'warning' => '#a16207',
            'warning_soft' => '#fefce8',
            'danger' => '#b91c1c',
            'danger_soft' => '#fef2f2',
            'radius' => '14px',
            'component_radius' => '10px',
            'content_width' => '600px',
            'logo_max_width' => '200px',
            'logo_max_height' => '64px',
            'heading_1_size' => '28px',
            'heading_2_size' => '23px',
            'heading_3_size' => '18px',
            'subtitle_size' => '13px',
            'body_size' => '15px',
            'small_size' => '12px',
        ],
    ],

    'testing' => [
        'enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_TESTING_ENABLED', false),
        'to_address' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_TESTING_TO_ADDRESS'),
        'to_name' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_TESTING_TO_NAME', 'Mail Test Inbox'),
        'respect_environment' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_TESTING_RESPECT_ENV', true),
        'environments' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_TESTING_ENVIRONMENTS', 'local,testing,staging')),
        ))),
    ],

    'providers' => [
        'default' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_PROVIDER'),
        'mailers' => [],
        'mailersend' => [
            'mailers' => ['mailersend'],
            'signing_secret' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_MAILERSEND_SIGNING_SECRET',
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
                'enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_MAILERSEND_MANAGEMENT_ENABLED',
                    false,
                ),
                'token' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_MAILERSEND_API_TOKEN',
                ),
                'domain_id' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_MAILERSEND_DOMAIN_ID',
                ),
                'api_url' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_MAILERSEND_API_URL',
                    'https://api.mailersend.com/v1',
                ),
                'timeout_seconds' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_MAILERSEND_TIMEOUT_SECONDS',
                    10,
                ),
                'connect_timeout_seconds' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_MAILERSEND_CONNECT_TIMEOUT_SECONDS',
                    3,
                ),
                'pagination' => [
                    'page_size' => 100,
                    'max_pages' => 10,
                ],
                'webhook' => [
                    'name' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_MAILERSEND_WEBHOOK_NAME',
                        'Mail Notifications',
                    ),
                    'url' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_MAILERSEND_WEBHOOK_URL',
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
        'enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_WEBHOOKS_ENABLED', true),
        'allowed_content_types' => [
            'application/json',
        ],
        'unknown_event_policy' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_WEBHOOK_UNKNOWN_EVENT_POLICY',
            'acknowledge',
        ),
        'unmatched_events' => [
            'policy' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_WEBHOOK_UNMATCHED_EVENT_POLICY',
                'retry_then_acknowledge',
            ),
            'retry_grace_seconds' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_WEBHOOK_UNMATCHED_RETRY_GRACE_SECONDS',
                300,
            ),
        ],
        'max_payload_bytes' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_WEBHOOK_MAX_PAYLOAD_BYTES',
            1_048_576,
        ),
    ],

    'integrations' => ['settings' => null],

    'scheduling' => [
        'enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULING_ENABLED', false),
        'delivery_profile' => null,
        'delivery_profile_setting' => null,
        'allowed_delivery_profiles' => [],
        'batch_size' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULING_BATCH_SIZE',
            50,
        ),
        'claim_ttl_seconds' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULING_CLAIM_TTL_SECONDS',
            300,
        ),
        'max_attempts' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULING_MAX_ATTEMPTS',
            3,
        ),
        'backoff_seconds' => [60, 300, 900],
        'max_payload_bytes' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULING_MAX_PAYLOAD_BYTES',
            65_536,
        ),
        'max_recipients' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULING_MAX_RECIPIENTS',
            1_000,
        ),
    ],

    'retention' => [
        'notifications' => [
            'days' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_RETENTION_DAYS',
                365,
            ),
            'statuses' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_RETENTION_STATUSES',
                    'delivered,opened,clicked,bounced,complained,rejected,failed,unsubscribed',
                )),
            ))),
        ],
        'scheduled_messages' => [
            'enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULED_RETENTION_ENABLED',
                false,
            ),
            'days' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULED_RETENTION_DAYS',
                90,
            ),
            'statuses' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULED_RETENTION_STATUSES',
                    'sent,failed,cancelled',
                )),
            ))),
        ],
        'batch_size' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_PRUNE_BATCH_SIZE',
            500,
        ),
        'limit' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_PRUNE_LIMIT',
            5_000,
        ),
        'anonymization' => [
            'enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_ANONYMIZATION_ENABLED',
                false,
            ),
            'notifications' => [
                'days' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_ANONYMIZATION_DAYS',
                    180,
                ),
                'statuses' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_ANONYMIZATION_STATUSES',
                        'delivered,opened,clicked,bounced,complained,rejected,failed,unsubscribed',
                    )),
                ))),
            ],
            'scheduled_messages' => [
                'enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_ENABLED',
                    false,
                ),
                'days' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_DAYS',
                    90,
                ),
                'statuses' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_STATUSES',
                        'sent,failed,cancelled',
                    )),
                ))),
            ],
            'batch_size' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_ANONYMIZATION_BATCH_SIZE',
                500,
            ),
            'limit' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_ANONYMIZATION_LIMIT',
                5_000,
            ),
        ],
    ],

    'storage' => [
        'connection' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_DB_CONNECTION'),
        'tables' => [
            'notifications' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_TABLE',
                MailNotificationsTables::Notifications,
            ),
            'events' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_EVENTS_TABLE',
                MailNotificationsTables::Events,
            ),
            'scheduled_messages' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SCHEDULED_MESSAGES_TABLE',
                MailNotificationsTables::ScheduledMessages,
            ),
        ],
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'privacy' => [
        'max_depth' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_METADATA_MAX_DEPTH',
            16,
        ),
        'max_items' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_METADATA_MAX_ITEMS',
            1_000,
        ),
        'max_string_bytes' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_METADATA_MAX_STRING_BYTES',
            16_384,
        ),
        'max_total_bytes' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_METADATA_MAX_TOTAL_BYTES',
            65_536,
        ),
        'sensitive_storage' => [
            'enabled' => PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SENSITIVE_STORAGE_ENABLED',
                false,
            ),
            'max_transformed_bytes' => (int) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_SENSITIVE_STORAGE_MAX_TRANSFORMED_BYTES',
                262_144,
            ),
        ],
        'redacted_keys' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) PackageEnvironment::get('NVL_MAIL_NOTIFICATIONS_REDACTED_KEYS',
                'authorization,cookie,password,token,secret,signature,api_key,two_factor_code,verification_code,magic_link,otp',
            )),
        ))),
    ],

];
