<?php

declare(strict_types=1);

use Nvl\MailNotifications\Services\ConfiguredMailNotificationReadAuthorization;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\Services\DefaultSensitiveDataRedactor;

return [
    'enabled' => env('MAIL_NOTIFICATIONS_ENABLED', true),

    'tracking' => [
        'enabled' => env('MAIL_NOTIFICATIONS_TRACKING_ENABLED', true),
        'failure_policy' => env('MAIL_NOTIFICATIONS_FAILURE_POLICY', 'fail_closed'),
        'excluded_mailers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_NOTIFICATIONS_EXCLUDED_MAILERS', '')),
        ))),
        'store_subject' => env('MAIL_NOTIFICATIONS_STORE_SUBJECT', true),
    ],

    'presentation' => [
        'enabled' => env('MAIL_NOTIFICATIONS_PRESENTATION_ENABLED', true),
        'auto_load' => env('MAIL_NOTIFICATIONS_PRESENTATION_AUTO_LOAD', true),
        'brand' => [
            'header_enabled' => env('MAIL_BRAND_HEADER_ENABLED', true),
            'footer_enabled' => env('MAIL_BRAND_FOOTER_ENABLED', true),
            'name' => env('MAIL_BRAND_NAME'),
            'url' => env('MAIL_BRAND_URL'),
            'logo_url' => env('MAIL_BRAND_LOGO_URL'),
            'logo_alt' => env('MAIL_BRAND_LOGO_ALT'),
            'support_text' => env('MAIL_BRAND_SUPPORT_TEXT'),
            'footer_text' => env('MAIL_BRAND_FOOTER_TEXT'),
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
        'enabled' => env('MAIL_TESTING_ENABLED', false),
        'to_address' => env('MAIL_TESTING_TO_ADDRESS'),
        'to_name' => env('MAIL_TESTING_TO_NAME', 'Mail Test Inbox'),
        'respect_environment' => env('MAIL_TESTING_RESPECT_ENV', true),
        'environments' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_TESTING_ENVIRONMENTS', 'local,testing,staging')),
        ))),
    ],

    'providers' => [
        'default' => env('MAIL_NOTIFICATIONS_PROVIDER'),
        'mailers' => [],
        'mailersend' => [
            'mailers' => ['mailersend'],
            'signing_secret' => env(
                'MAIL_NOTIFICATIONS_MAILERSEND_SIGNING_SECRET',
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
                'enabled' => env(
                    'MAIL_NOTIFICATIONS_MAILERSEND_MANAGEMENT_ENABLED',
                    false,
                ),
                'token' => env(
                    'MAIL_NOTIFICATIONS_MAILERSEND_API_TOKEN',
                ),
                'domain_id' => env(
                    'MAIL_NOTIFICATIONS_MAILERSEND_DOMAIN_ID',
                ),
                'api_url' => env(
                    'MAIL_NOTIFICATIONS_MAILERSEND_API_URL',
                    'https://api.mailersend.com/v1',
                ),
                'timeout_seconds' => (int) env(
                    'MAIL_NOTIFICATIONS_MAILERSEND_TIMEOUT_SECONDS',
                    10,
                ),
                'connect_timeout_seconds' => (int) env(
                    'MAIL_NOTIFICATIONS_MAILERSEND_CONNECT_TIMEOUT_SECONDS',
                    3,
                ),
                'pagination' => [
                    'page_size' => 100,
                    'max_pages' => 10,
                ],
                'webhook' => [
                    'name' => env(
                        'MAIL_NOTIFICATIONS_MAILERSEND_WEBHOOK_NAME',
                        'Mail Notifications',
                    ),
                    'url' => env(
                        'MAIL_NOTIFICATIONS_MAILERSEND_WEBHOOK_URL',
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
        'suggestion_limit' => 20,
        'authorization' => [
            'class' => ConfiguredMailNotificationReadAuthorization::class,
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
        'enabled' => env('MAIL_NOTIFICATIONS_WEBHOOKS_ENABLED', true),
        'allowed_content_types' => [
            'application/json',
        ],
        'unknown_event_policy' => env(
            'MAIL_NOTIFICATIONS_WEBHOOK_UNKNOWN_EVENT_POLICY',
            'acknowledge',
        ),
        'unmatched_events' => [
            'policy' => env(
                'MAIL_NOTIFICATIONS_WEBHOOK_UNMATCHED_EVENT_POLICY',
                'retry_then_acknowledge',
            ),
            'retry_grace_seconds' => (int) env(
                'MAIL_NOTIFICATIONS_WEBHOOK_UNMATCHED_RETRY_GRACE_SECONDS',
                300,
            ),
        ],
        'max_payload_bytes' => (int) env(
            'MAIL_NOTIFICATIONS_WEBHOOK_MAX_PAYLOAD_BYTES',
            1_048_576,
        ),
    ],

    'scheduling' => [
        'enabled' => env('MAIL_NOTIFICATIONS_SCHEDULING_ENABLED', false),
        'batch_size' => (int) env(
            'MAIL_NOTIFICATIONS_SCHEDULING_BATCH_SIZE',
            50,
        ),
        'claim_ttl_seconds' => (int) env(
            'MAIL_NOTIFICATIONS_SCHEDULING_CLAIM_TTL_SECONDS',
            300,
        ),
        'max_attempts' => (int) env(
            'MAIL_NOTIFICATIONS_SCHEDULING_MAX_ATTEMPTS',
            3,
        ),
        'backoff_seconds' => [60, 300, 900],
        'max_payload_bytes' => (int) env(
            'MAIL_NOTIFICATIONS_SCHEDULING_MAX_PAYLOAD_BYTES',
            65_536,
        ),
        'max_recipients' => (int) env(
            'MAIL_NOTIFICATIONS_SCHEDULING_MAX_RECIPIENTS',
            1_000,
        ),
    ],

    'retention' => [
        'notifications' => [
            'days' => (int) env(
                'MAIL_NOTIFICATIONS_RETENTION_DAYS',
                365,
            ),
            'statuses' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env(
                    'MAIL_NOTIFICATIONS_RETENTION_STATUSES',
                    'delivered,opened,clicked,bounced,complained,rejected,failed,unsubscribed',
                )),
            ))),
        ],
        'scheduled_messages' => [
            'enabled' => env(
                'MAIL_NOTIFICATIONS_SCHEDULED_RETENTION_ENABLED',
                false,
            ),
            'days' => (int) env(
                'MAIL_NOTIFICATIONS_SCHEDULED_RETENTION_DAYS',
                90,
            ),
            'statuses' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env(
                    'MAIL_NOTIFICATIONS_SCHEDULED_RETENTION_STATUSES',
                    'sent,failed,cancelled',
                )),
            ))),
        ],
        'batch_size' => (int) env(
            'MAIL_NOTIFICATIONS_PRUNE_BATCH_SIZE',
            500,
        ),
        'limit' => (int) env(
            'MAIL_NOTIFICATIONS_PRUNE_LIMIT',
            5_000,
        ),
        'anonymization' => [
            'enabled' => env(
                'MAIL_NOTIFICATIONS_ANONYMIZATION_ENABLED',
                false,
            ),
            'notifications' => [
                'days' => (int) env(
                    'MAIL_NOTIFICATIONS_ANONYMIZATION_DAYS',
                    180,
                ),
                'statuses' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env(
                        'MAIL_NOTIFICATIONS_ANONYMIZATION_STATUSES',
                        'delivered,opened,clicked,bounced,complained,rejected,failed,unsubscribed',
                    )),
                ))),
            ],
            'scheduled_messages' => [
                'enabled' => env(
                    'MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_ENABLED',
                    false,
                ),
                'days' => (int) env(
                    'MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_DAYS',
                    90,
                ),
                'statuses' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env(
                        'MAIL_NOTIFICATIONS_SCHEDULED_ANONYMIZATION_STATUSES',
                        'sent,failed,cancelled',
                    )),
                ))),
            ],
            'batch_size' => (int) env(
                'MAIL_NOTIFICATIONS_ANONYMIZATION_BATCH_SIZE',
                500,
            ),
            'limit' => (int) env(
                'MAIL_NOTIFICATIONS_ANONYMIZATION_LIMIT',
                5_000,
            ),
        ],
    ],

    'storage' => [
        'connection' => env('MAIL_NOTIFICATIONS_DB_CONNECTION'),
        'tables' => [
            'notifications' => env(
                'MAIL_NOTIFICATIONS_TABLE',
                'mail_notifications',
            ),
            'events' => env(
                'MAIL_NOTIFICATION_EVENTS_TABLE',
                'mail_notification_events',
            ),
            'scheduled_messages' => env(
                'MAIL_NOTIFICATIONS_SCHEDULED_MESSAGES_TABLE',
                'scheduled_mail_messages',
            ),
        ],
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'privacy' => [
        'max_depth' => (int) env(
            'MAIL_NOTIFICATIONS_METADATA_MAX_DEPTH',
            16,
        ),
        'max_items' => (int) env(
            'MAIL_NOTIFICATIONS_METADATA_MAX_ITEMS',
            1_000,
        ),
        'max_string_bytes' => (int) env(
            'MAIL_NOTIFICATIONS_METADATA_MAX_STRING_BYTES',
            16_384,
        ),
        'max_total_bytes' => (int) env(
            'MAIL_NOTIFICATIONS_METADATA_MAX_TOTAL_BYTES',
            65_536,
        ),
        'sensitive_storage' => [
            'enabled' => env(
                'MAIL_NOTIFICATIONS_SENSITIVE_STORAGE_ENABLED',
                false,
            ),
            'max_transformed_bytes' => (int) env(
                'MAIL_NOTIFICATIONS_SENSITIVE_STORAGE_MAX_TRANSFORMED_BYTES',
                262_144,
            ),
        ],
        'redacted_keys' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'MAIL_NOTIFICATIONS_REDACTED_KEYS',
                'authorization,cookie,password,token,secret,signature,api_key,two_factor_code,verification_code,magic_link,otp',
            )),
        ))),
    ],

];
