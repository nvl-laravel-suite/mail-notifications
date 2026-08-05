<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Exceptions\MailRetentionException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\Services\MailRetentionPruner;

/**
 * Persist one tracked notification at explicit lifecycle instants.
 */
function retentionNotification(
    MailDeliveryStatus $status,
    CarbonImmutable $createdAt,
    ?CarbonImmutable $statusChangedAt = null,
): MailNotification {
    $notification = new MailNotification;
    $notification->forceFill([
        'correlation_id' => (string) Str::uuid(),
        'mailer' => 'array',
        'provider' => 'test-provider',
        'provider_message_id' => (string) Str::uuid(),
        'status' => $status->value,
        'message_category' => 'test.retention',
        'to_recipients' => [[
            'email' => 'recipient@example.test',
            'name' => null,
        ]],
        'status_changed_at' => $statusChangedAt,
        'created_at' => $createdAt,
        'updated_at' => $statusChangedAt ?? $createdAt,
    ])->save();

    return $notification;
}

/**
 * Persist one provider event for a tracked notification.
 */
function retentionProviderEvent(
    MailNotification $notification,
    CarbonImmutable $occurredAt,
): MailNotificationEvent {
    $event = new MailNotificationEvent;
    $event->forceFill([
        'mail_notification_id' => $notification->id,
        'provider' => 'test-provider',
        'provider_event_id' => (string) Str::uuid(),
        'provider_message_id' => $notification->provider_message_id,
        'normalized_type' => MailDeliveryStatus::Delivered->value,
        'occurred_at' => $occurredAt,
        'processed_at' => $occurredAt,
        'created_at' => $occurredAt,
        'updated_at' => $occurredAt,
    ])->save();

    return $event;
}

/**
 * Persist one scheduled message at explicit creation and terminal instants.
 */
function retentionScheduledMessage(
    ScheduledMailStatus $status,
    CarbonImmutable $createdAt,
    ?CarbonImmutable $terminalAt = null,
): ScheduledMailMessage {
    $message = new ScheduledMailMessage;
    $terminalAttributes = match ($status) {
        ScheduledMailStatus::Sent => ['sent_at' => $terminalAt],
        ScheduledMailStatus::Failed => ['failed_at' => $terminalAt],
        ScheduledMailStatus::Cancelled => ['cancelled_at' => $terminalAt],
        ScheduledMailStatus::Pending,
        ScheduledMailStatus::Processing => [],
    };
    $message->forceFill([
        'factory_alias' => 'test.retention',
        'payload_version' => 1,
        'payload' => ['message' => 'retention'],
        'to_recipients' => [[
            'email' => 'recipient@example.test',
            'name' => null,
        ]],
        'status' => $status->value,
        'scheduled_for' => $createdAt,
        'available_at' => $createdAt,
        'attempts' => 1,
        'max_attempts' => 3,
        'created_at' => $createdAt,
        'updated_at' => $terminalAt ?? $createdAt,
        ...$terminalAttributes,
    ])->save();

    return $message;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-30 12:00:00 UTC');
    config()->set('app.timezone', 'Europe/Sofia');
    config()->set('mail-notifications.retention', [
        'notifications' => [
            'days' => 30,
            'statuses' => ['failed'],
        ],
        'scheduled_messages' => [
            'enabled' => true,
            'days' => 30,
            'statuses' => ['sent', 'failed', 'cancelled'],
        ],
        'batch_size' => 2,
        'limit' => 100,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('reports deterministic UTC dry-run counts without mutation', function () {
    $old = CarbonImmutable::now('UTC')->subDays(31);
    $cutoff = CarbonImmutable::now('UTC')->subDays(30);
    $eligible = retentionNotification(
        MailDeliveryStatus::Failed,
        $old,
        $old,
    );
    retentionProviderEvent($eligible, $old);
    retentionProviderEvent($eligible, $old->addMinute());
    retentionNotification(MailDeliveryStatus::Accepted, $old, $old);
    retentionNotification(MailDeliveryStatus::Failed, $cutoff, $cutoff);
    retentionScheduledMessage(ScheduledMailStatus::Sent, $old, $old);
    retentionScheduledMessage(ScheduledMailStatus::Pending, $old);
    retentionScheduledMessage(ScheduledMailStatus::Processing, $old);
    retentionScheduledMessage(
        ScheduledMailStatus::Cancelled,
        $cutoff,
        $cutoff,
    );

    $result = app(MailRetentionPruner::class)->prune(dryRun: true);
    $repeated = app(MailRetentionPruner::class)->prune(dryRun: true);

    expect($result)
        ->dryRun->toBeTrue()
        ->notificationCount->toBe(1)
        ->providerEventCount->toBe(2)
        ->scheduledMessageCount->toBe(1)
        ->and($result->notificationCutoff->toIso8601String())
        ->toBe('2026-06-30T12:00:00+00:00')
        ->and($result->notificationCutoff->getTimezone()->getName())
        ->toBe('UTC')
        ->and($result->scheduledMessageCutoff?->getTimezone()->getName())
        ->toBe('UTC')
        ->and($repeated)->toEqual($result)
        ->and(MailNotification::query()->count())->toBe(3)
        ->and(MailNotificationEvent::query()->count())->toBe(2)
        ->and(ScheduledMailMessage::query()->count())->toBe(4);
});

it('preserves microseconds in explicit retention cutoff comparisons', function () {
    $cutoff = CarbonImmutable::parse(
        '2026-06-30T12:00:00.123456Z',
    );
    $eligibleAt = $cutoff->subMicrosecond();
    retentionNotification(
        MailDeliveryStatus::Failed,
        $eligibleAt,
        $eligibleAt,
    );
    retentionNotification(
        MailDeliveryStatus::Failed,
        $cutoff,
        $cutoff,
    );
    retentionScheduledMessage(
        ScheduledMailStatus::Failed,
        $eligibleAt,
        $eligibleAt,
    );
    retentionScheduledMessage(
        ScheduledMailStatus::Failed,
        $cutoff,
        $cutoff,
    );

    $result = app(MailRetentionPruner::class)->prune(
        dryRun: true,
        cutoff: $cutoff,
    );

    expect($result)
        ->notificationCount->toBe(1)
        ->scheduledMessageCount->toBe(1)
        ->and($result->notificationCutoff->format('U.u'))
        ->toBe($cutoff->format('U.u'));
});

it('prunes allowlisted history and cascades provider events', function () {
    $old = CarbonImmutable::now('UTC')->subDays(60);
    $notification = retentionNotification(
        MailDeliveryStatus::Failed,
        $old,
        $old,
    );
    $event = retentionProviderEvent($notification, $old);
    $protectedStatus = retentionNotification(
        MailDeliveryStatus::Delivered,
        $old,
        $old,
    );
    $sent = retentionScheduledMessage(
        ScheduledMailStatus::Sent,
        $old,
        $old,
    );
    $pending = retentionScheduledMessage(ScheduledMailStatus::Pending, $old);
    $processing = retentionScheduledMessage(
        ScheduledMailStatus::Processing,
        $old,
    );

    $result = app(MailRetentionPruner::class)->prune();

    expect($result)
        ->dryRun->toBeFalse()
        ->notificationCount->toBe(1)
        ->providerEventCount->toBe(1)
        ->scheduledMessageCount->toBe(1);
    $this->assertModelMissing($notification);
    $this->assertModelMissing($event);
    $this->assertModelMissing($sent);
    $this->assertModelExists($protectedStatus);
    $this->assertModelExists($pending);
    $this->assertModelExists($processing);
});

it('protects old rows whose lifecycle completed recently', function () {
    $createdAt = CarbonImmutable::now('UTC')->subYear();
    $recentTransition = CarbonImmutable::now('UTC')->subDay();
    $notification = retentionNotification(
        MailDeliveryStatus::Failed,
        $createdAt,
        $recentTransition,
    );
    $scheduled = retentionScheduledMessage(
        ScheduledMailStatus::Sent,
        $createdAt,
        $recentTransition,
    );

    $result = app(MailRetentionPruner::class)->prune();

    expect($result)
        ->notificationCount->toBe(0)
        ->scheduledMessageCount->toBe(0);
    $this->assertModelExists($notification);
    $this->assertModelExists($scheduled);
});

it('uses created and updated timestamps only as missing lifecycle fallbacks', function () {
    $old = CarbonImmutable::now('UTC')->subDays(60);
    $notification = retentionNotification(
        MailDeliveryStatus::Failed,
        $old,
    );
    $scheduled = retentionScheduledMessage(
        ScheduledMailStatus::Failed,
        $old,
    );

    $result = app(MailRetentionPruner::class)->prune();

    expect($result)
        ->notificationCount->toBe(1)
        ->scheduledMessageCount->toBe(1);
    $this->assertModelMissing($notification);
    $this->assertModelMissing($scheduled);
});

it('enforces a deterministic per-data-set limit in bounded batches', function () {
    config()->set('mail-notifications.retention.batch_size', 1);
    $notifications = [];
    $scheduledMessages = [];

    foreach ([90, 80, 70] as $days) {
        $completedAt = CarbonImmutable::now('UTC')->subDays($days);
        $notifications[] = retentionNotification(
            MailDeliveryStatus::Failed,
            $completedAt->subDay(),
            $completedAt,
        );
        $scheduledMessages[] = retentionScheduledMessage(
            ScheduledMailStatus::Sent,
            $completedAt->subDay(),
            $completedAt,
        );
    }

    $result = app(MailRetentionPruner::class)->prune(limit: 2);

    expect($result)
        ->notificationCount->toBe(2)
        ->scheduledMessageCount->toBe(2);
    $this->assertModelMissing($notifications[0]);
    $this->assertModelMissing($notifications[1]);
    $this->assertModelExists($notifications[2]);
    $this->assertModelMissing($scheduledMessages[0]);
    $this->assertModelMissing($scheduledMessages[1]);
    $this->assertModelExists($scheduledMessages[2]);
});

it('supports tracking-only schemas when scheduled pruning is disabled', function () {
    config()->set(
        'mail-notifications.retention.scheduled_messages.enabled',
        false,
    );
    Schema::drop((new ScheduledMailMessage)->getTable());
    $old = CarbonImmutable::now('UTC')->subDays(60);
    $notification = retentionNotification(
        MailDeliveryStatus::Failed,
        $old,
        $old,
    );

    $result = app(MailRetentionPruner::class)->prune();

    expect($result)
        ->notificationCount->toBe(1)
        ->scheduledMessageCount->toBe(0)
        ->scheduledMessageCutoff->toBeNull();
    $this->assertModelMissing($notification);
});

it('rejects unsafe or unbounded retention configuration', function (
    string $key,
    mixed $value,
    string $message,
) {
    config()->set($key, $value);

    expect(static fn () => app(MailRetentionPruner::class)->prune(
        dryRun: true,
    ))->toThrow(MailRetentionException::class, $message);
})->with([
    'notification days' => [
        'mail-notifications.retention.notifications.days',
        0,
        'notification retention days',
    ],
    'notification statuses' => [
        'mail-notifications.retention.notifications.statuses',
        ['unknown'],
        'valid delivery status strings',
    ],
    'scheduled switch' => [
        'mail-notifications.retention.scheduled_messages.enabled',
        'yes',
        'enabled must be a boolean',
    ],
    'scheduled active status' => [
        'mail-notifications.retention.scheduled_messages.statuses',
        ['pending'],
        'pending and processing are always protected',
    ],
    'batch size' => [
        'mail-notifications.retention.batch_size',
        0,
        'batch size',
    ],
    'limit' => [
        'mail-notifications.retention.limit',
        10_001,
        'limit',
    ],
]);

it('reports retention configuration as a separate doctor check', function (
    string $key,
    mixed $value,
    string $message,
) {
    config()->set($key, $value);

    $check = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'retention.configuration');

    expect($check)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->severity->toBe('error')
        ->message->toContain($message);
})->with([
    'notification days' => [
        'mail-notifications.retention.notifications.days',
        0,
        'notification retention days',
    ],
    'notification statuses' => [
        'mail-notifications.retention.notifications.statuses',
        [],
        'statuses must be a non-empty array',
    ],
    'batch size' => [
        'mail-notifications.retention.batch_size',
        1_001,
        'batch size',
    ],
    'limit' => [
        'mail-notifications.retention.limit',
        10_001,
        'limit',
    ],
    'scheduled days when enabled' => [
        'mail-notifications.retention.scheduled_messages.days',
        0,
        'scheduled-message retention days',
    ],
    'scheduled statuses when enabled' => [
        'mail-notifications.retention.scheduled_messages.statuses',
        ['processing'],
        'pending and processing are always protected',
    ],
]);

it('skips scheduled retention validation and storage when disabled', function () {
    config()->set(
        'mail-notifications.retention.scheduled_messages.enabled',
        false,
    );
    config()->set(
        'mail-notifications.retention.scheduled_messages.days',
        0,
    );
    config()->set(
        'mail-notifications.retention.scheduled_messages.statuses',
        ['processing'],
    );
    Schema::drop((new ScheduledMailMessage)->getTable());

    $check = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'retention.configuration');

    expect($check)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->message->toContain('scheduled-message retention is disabled');
});

it('fails the strict doctor for invalid retention configuration', function () {
    config()->set('mail-notifications.retention.limit', 10_001);

    $this->artisan('nvl:mail-notifications:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->expectsOutputToContain('"key": "retention.configuration"')
        ->assertFailed();
});

it('supports deterministic command previews and explicit UTC cutoffs', function () {
    $old = CarbonImmutable::parse('2026-06-01T00:00:00Z');
    $notification = retentionNotification(
        MailDeliveryStatus::Failed,
        $old,
        $old,
    );

    $this->artisan('nvl:mail-notifications:prune', [
        '--dry-run' => true,
        '--before' => '2026-07-01T00:00:00+00:00',
        '--limit' => 1,
    ])->expectsOutputToContain(
        'retention dry run completed; no rows were deleted',
    )->expectsOutputToContain('Tracked notifications: 1')
        ->expectsOutputToContain('Provider events via cascade: 0')
        ->assertSuccessful();

    $this->assertModelExists($notification);
});

it('returns invalid for malformed command options', function (
    array $options,
    string $message,
) {
    $this->artisan(
        'nvl:mail-notifications:prune',
        $options,
    )->expectsOutputToContain($message)
        ->assertExitCode(Command::INVALID);
})->with([
    'zero limit' => [
        ['--limit' => '0'],
        'The --limit option must be an integer',
    ],
    'malformed cutoff' => [
        ['--before' => 'yesterday'],
        'The --before option must be a non-future RFC 3339 timestamp',
    ],
    'future cutoff' => [
        ['--before' => '2026-07-31T00:00:00Z'],
        'The --before option must be a non-future RFC 3339 timestamp',
    ],
]);

it('returns failure for invalid configuration and database errors', function () {
    config()->set(
        'mail-notifications.retention.scheduled_messages.statuses',
        ['processing'],
    );

    $this->artisan('nvl:mail-notifications:prune', [
        '--dry-run' => true,
    ])->expectsOutputToContain('pending and processing are always protected')
        ->assertExitCode(Command::FAILURE);

    config()->set(
        'mail-notifications.retention.scheduled_messages.enabled',
        false,
    );
    config()->set(
        'mail-notifications.storage.tables.notifications',
        'missing_retention_notifications',
    );

    $this->artisan('nvl:mail-notifications:prune', [
        '--dry-run' => true,
    ])->expectsOutputToContain('Mail notification pruning failed')
        ->assertExitCode(Command::FAILURE);
});
