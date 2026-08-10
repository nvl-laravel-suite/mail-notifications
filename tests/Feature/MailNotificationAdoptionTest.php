<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Actions\AdoptMailNotificationsAction;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\MailNotificationsAdoptionManifest;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Tests\Fixtures\ScheduledTestFactory;

function mailNotificationAdoptionAction(): AdoptMailNotificationsAction
{
    return new AdoptMailNotificationsAction(
        new MailNotificationsAdoptionManifest,
        new MailNotificationNotifiableTypeRegistry,
        new ScheduledMessageFactoryRegistry([new ScheduledTestFactory]),
    );
}

/**
 * @return array<string, mixed>
 */
function mailNotificationAdoptionManifest(
    bool $dropSources = false,
): array {
    return [
        'version' => 1,
        'connection' => config('database.default'),
        'notifications' => [
            'table' => 'legacy_mail_notifications',
            'expected_count' => 1,
            'columns' => [
                'id' => 'id',
                'status' => 'legacy_status',
                'mailer' => 'driver',
                'provider_message_id' => 'provider_message_id',
                'message_category' => 'category',
                'subject' => 'subject',
                'to_recipients' => 'recipients',
                'metadata' => 'metadata',
                'accepted_at' => 'sent_at',
                'delivered_at' => 'delivered_at',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'status_map' => [
                'sent' => 'accepted',
            ],
            'notifiable_type_map' => [],
            'event_timestamps' => [
                'delivered' => 'delivered_at',
            ],
            'metadata_allowlist' => ['support_code'],
        ],
        'scheduled_messages' => [
            'table' => 'legacy_scheduled_mail',
            'expected_count' => 1,
            'columns' => [
                'id' => 'id',
                'factory_key' => 'mail_class',
                'payload' => 'payload',
                'to_recipients' => 'recipients',
                'status' => 'legacy_status',
                'scheduled_for' => 'scheduled_for',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'status_map' => [
                'queued' => 'pending',
            ],
            'notifiable_type_map' => [],
            'factory_map' => [
                'Legacy\\ReceiptMail' => [
                    'alias' => 'test.scheduled',
                    'version' => 1,
                ],
            ],
            'metadata_allowlist' => [],
        ],
        'foreign_keys' => [
            [
                'table' => 'legacy_reminders',
                'column' => 'mail_notification_id',
                'name' => 'legacy_reminders_mail_notification_foreign',
                'on_delete' => 'null',
            ],
        ],
        'drop_sources' => $dropSources,
    ];
}

beforeEach(function (): void {
    Schema::create('legacy_mail_notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('legacy_status');
        $table->string('driver');
        $table->string('provider_message_id')->nullable();
        $table->string('category');
        $table->text('subject')->nullable();
        $table->json('recipients');
        $table->json('metadata')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamp('delivered_at')->nullable();
        $table->timestamps();
    });
    Schema::create('legacy_scheduled_mail', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('mail_class');
        $table->json('payload');
        $table->json('recipients');
        $table->string('legacy_status');
        $table->timestamp('scheduled_for');
        $table->timestamps();
    });
    Schema::create('legacy_reminders', function (Blueprint $table): void {
        $table->id();
        $table->uuid('mail_notification_id')->nullable();
    });
});

it('dry-runs and applies a reconciled privacy-bounded legacy import', function (): void {
    $notificationId = (string) Str::uuid();
    $scheduledId = (string) Str::uuid();
    $now = now('UTC')->startOfSecond();
    DB::table('legacy_mail_notifications')->insert([
        'id' => $notificationId,
        'legacy_status' => 'sent',
        'driver' => 'mailersend',
        'provider_message_id' => 'legacy-provider-1',
        'category' => 'receipt',
        'subject' => 'Legacy receipt',
        'recipients' => json_encode([
            ['email' => 'recipient@example.test', 'name' => null],
        ], JSON_THROW_ON_ERROR),
        'metadata' => json_encode([
            'support_code' => 'case-1',
            'secret_token' => 'must-not-import',
        ], JSON_THROW_ON_ERROR),
        'sent_at' => $now,
        'delivered_at' => $now->addMinute(),
        'created_at' => $now,
        'updated_at' => $now->addMinute(),
    ]);
    DB::table('legacy_scheduled_mail')->insert([
        'id' => $scheduledId,
        'mail_class' => 'Legacy\\ReceiptMail',
        'payload' => json_encode(['body' => 'Scheduled body'], JSON_THROW_ON_ERROR),
        'recipients' => json_encode([
            ['email' => 'recipient@example.test', 'name' => null],
        ], JSON_THROW_ON_ERROR),
        'legacy_status' => 'queued',
        'scheduled_for' => $now->addHour(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('legacy_reminders')->insert([
        'mail_notification_id' => $notificationId,
    ]);
    $manifest = mailNotificationAdoptionManifest(dropSources: true);

    $plan = mailNotificationAdoptionAction()->execute($manifest);

    expect($plan['mode'])->toBe('plan')
        ->and($plan['reconciliation']['notifications']['source'])->toBe(1)
        ->and($plan['reconciliation']['events']['generated'])->toBe(1)
        ->and($plan['reconciliation']['scheduled_messages']['source'])->toBe(1)
        ->and(MailNotification::query()->count())->toBe(0)
        ->and(ScheduledMailMessage::query()->count())->toBe(0);

    $result = mailNotificationAdoptionAction()->execute($manifest, apply: true);
    $notification = MailNotification::query()->findOrFail($notificationId);
    $scheduled = ScheduledMailMessage::query()->findOrFail($scheduledId);

    expect($result['mode'])->toBe('apply')
        ->and($result['foreign_keys_restored'])->toBe(1)
        ->and($notification->status->value)->toBe('accepted')
        ->and($notification->metadata)->toBe([
            'support_code' => 'case-1',
            'imported_from' => 'mail_notifications_adoption_v1',
        ])
        ->and(MailNotificationEvent::query()->where(
            'mail_notification_id',
            $notificationId,
        )->value('normalized_type'))->toBe(MailDeliveryStatus::Delivered)
        ->and($scheduled->factory_alias)->toBe('test.scheduled')
        ->and($scheduled->payload_version)->toBe(1)
        ->and($scheduled->claim_token)->toBeNull()
        ->and($scheduled->locked_until)->toBeNull()
        ->and(Schema::hasTable('legacy_mail_notifications'))->toBeFalse()
        ->and(Schema::hasTable('legacy_scheduled_mail'))->toBeFalse()
        ->and(collect(Schema::getForeignKeys('legacy_reminders'))->contains(
            static fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['mail_notification_id']
                && ($foreignKey['foreign_table'] ?? null) === 'mail_notifications',
        ))->toBeTrue();
});

it('fails loudly on incomplete status and scheduled factory mappings', function (): void {
    $notificationId = (string) Str::uuid();
    $scheduledId = (string) Str::uuid();
    $manifest = mailNotificationAdoptionManifest();
    $manifest['notifications']['status_map'] = ['pending' => 'pending'];

    DB::table('legacy_mail_notifications')->insert([
        'id' => $notificationId,
        'legacy_status' => 'unknown',
        'driver' => 'array',
        'provider_message_id' => null,
        'category' => 'legacy',
        'subject' => null,
        'recipients' => '[]',
        'metadata' => null,
        'sent_at' => null,
        'delivered_at' => null,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    expect(fn () => mailNotificationAdoptionAction()->execute($manifest))
        ->toThrow(InvalidArgumentException::class, 'has no explicit mapping');
});

it('requires every persisted scheduled class to map to a registered versioned factory', function (): void {
    $manifest = mailNotificationAdoptionManifest();
    $manifest['notifications'] = null;
    $manifest['scheduled_messages']['factory_map'] = [];
    DB::table('legacy_scheduled_mail')->insert([
        'id' => (string) Str::uuid(),
        'mail_class' => 'Legacy\\UnknownMail',
        'payload' => json_encode(['body' => 'Scheduled body'], JSON_THROW_ON_ERROR),
        'recipients' => '[]',
        'legacy_status' => 'queued',
        'scheduled_for' => now('UTC')->addHour(),
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    expect(fn () => mailNotificationAdoptionAction()->execute($manifest))
        ->toThrow(InvalidArgumentException::class, 'has no explicit mapping');
});

it('plans and applies a separate pre-migration table staging phase', function (): void {
    Schema::create('mail_notifications_collision', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
    Schema::create('legacy_stage_links', function (Blueprint $table): void {
        $table->id();
        $table->uuid('mail_notification_id')->nullable();
        $table->foreign(
            'mail_notification_id',
            'legacy_stage_links_mail_notification_foreign',
        )->references('id')->on('mail_notifications_collision')->nullOnDelete();
    });
    $manifest = mailNotificationAdoptionManifest();
    $manifest['staging'] = [[
        'source_table' => 'mail_notifications_collision',
        'staging_table' => 'mail_notifications_staged',
    ]];
    $manifest['foreign_keys'] = [[
        'table' => 'legacy_stage_links',
        'column' => 'mail_notification_id',
        'name' => 'legacy_stage_links_mail_notification_foreign',
        'on_delete' => 'null',
    ]];

    $plan = mailNotificationAdoptionAction()->execute($manifest, stage: true);

    expect($plan['mode'])->toBe('plan')
        ->and(Schema::hasTable('mail_notifications_collision'))->toBeTrue()
        ->and(Schema::hasTable('mail_notifications_staged'))->toBeFalse();

    mailNotificationAdoptionAction()->execute($manifest, stage: true, apply: true);

    expect(Schema::hasTable('mail_notifications_collision'))->toBeFalse()
        ->and(Schema::hasTable('mail_notifications_staged'))->toBeTrue()
        ->and(Schema::getForeignKeys('legacy_stage_links'))->toBe([]);
});

it('registers the dry-run adoption command', function (): void {
    expect(Artisan::all())->toHaveKey('nvl:mail-notifications:adopt');
});
