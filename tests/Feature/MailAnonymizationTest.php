<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Exceptions\MailRetentionException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\MailHistoryAnonymizer;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;

/**
 * Persist one identifying tracked notification for anonymization.
 */
function identifyingNotification(
    CarbonImmutable $completedAt,
): MailNotification {
    return MailNotification::query()->create([
        'correlation_id' => (string) Str::uuid(),
        'queue_reference' => (string) Str::uuid(),
        'mailer' => 'array',
        'provider' => 'provider-test',
        'provider_message_id' => (string) Str::uuid(),
        'status' => MailDeliveryStatus::Failed,
        'message_category' => 'test.anonymization',
        'subject' => 'Identifying subject',
        'from_email' => 'sender@example.test',
        'from_name' => 'Identifying Sender',
        'to_recipients' => [[
            'email' => 'recipient@example.test',
            'name' => 'Identifying Recipient',
        ]],
        'cc_recipients' => [[
            'email' => 'copy@example.test',
            'name' => null,
        ]],
        'bcc_recipients' => [[
            'email' => 'hidden@example.test',
            'name' => null,
        ]],
        'primary_recipient_email' => 'recipient@example.test',
        'notifiable_type' => 'customer',
        'notifiable_id' => 'customer-123',
        'metadata' => ['private_reference' => 'private-value'],
        'failed_at' => $completedAt,
        'status_changed_at' => $completedAt,
        'created_at' => $completedAt->subDay(),
        'updated_at' => $completedAt,
    ]);
}

/**
 * Persist one identifying provider event for anonymization.
 */
function identifyingProviderEvent(
    MailNotification $notification,
    CarbonImmutable $completedAt,
): MailNotificationEvent {
    return MailNotificationEvent::query()->create([
        'mail_notification_id' => $notification->id,
        'provider' => 'provider-test',
        'provider_event_id' => (string) Str::uuid(),
        'provider_message_id' => $notification->provider_message_id,
        'normalized_type' => MailDeliveryStatus::Failed,
        'occurred_at' => $completedAt,
        'metadata' => ['private_provider_value' => 'private-value'],
        'processed_at' => $completedAt,
        'created_at' => $completedAt,
        'updated_at' => $completedAt,
    ]);
}

/**
 * Persist one identifying terminal scheduled message for anonymization.
 */
function identifyingScheduledMessage(
    CarbonImmutable $completedAt,
): ScheduledMailMessage {
    return ScheduledMailMessage::query()->create([
        'factory_alias' => 'test.anonymization',
        'payload_version' => 1,
        'payload' => ['private_payload' => 'private-value'],
        'to_recipients' => [[
            'email' => 'scheduled@example.test',
            'name' => 'Scheduled Recipient',
        ]],
        'cc_recipients' => [[
            'email' => 'scheduled-copy@example.test',
            'name' => null,
        ]],
        'bcc_recipients' => [],
        'status' => ScheduledMailStatus::Failed,
        'scheduled_for' => $completedAt->subDay(),
        'available_at' => $completedAt->subDay(),
        'attempts' => 1,
        'max_attempts' => 3,
        'last_error' => 'PrivateFailureType',
        'notifiable_type' => 'customer',
        'notifiable_id' => 'customer-123',
        'metadata' => ['private_reference' => 'private-value'],
        'failed_at' => $completedAt,
        'created_at' => $completedAt->subDay(),
        'updated_at' => $completedAt,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-30 12:00:00 UTC');
    config()->set('mail-notifications.retention.anonymization', [
        'enabled' => true,
        'notifications' => [
            'days' => 30,
            'statuses' => ['failed'],
        ],
        'scheduled_messages' => [
            'enabled' => true,
            'days' => 30,
            'statuses' => ['failed'],
        ],
        'batch_size' => 2,
        'limit' => 100,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('preserves microseconds in explicit anonymization cutoff comparisons', function () {
    $cutoff = CarbonImmutable::parse(
        '2026-06-30T12:00:00.123456Z',
    );
    $eligibleAt = $cutoff->subMicrosecond();
    $eligible = identifyingNotification($eligibleAt);
    identifyingProviderEvent($eligible, $eligibleAt);
    identifyingNotification($cutoff);
    identifyingScheduledMessage($eligibleAt);
    identifyingScheduledMessage($cutoff);

    $result = app(MailHistoryAnonymizer::class)->anonymize(
        dryRun: true,
        cutoff: $cutoff,
    );

    expect($result)
        ->notificationCount->toBe(1)
        ->providerEventCount->toBe(1)
        ->scheduledMessageCount->toBe(1)
        ->and($result->notificationCutoff->format('U.u'))
        ->toBe($cutoff->format('U.u'));
});

it('previews and then anonymizes history without deleting lifecycle identities', function () {
    $completedAt = CarbonImmutable::now('UTC')->subDays(60);
    $notification = identifyingNotification($completedAt);
    $event = identifyingProviderEvent($notification, $completedAt);
    $scheduled = identifyingScheduledMessage($completedAt);
    $queueReference = $notification->queue_reference;
    $providerMessageId = $notification->provider_message_id;
    $providerEventId = $event->provider_event_id;

    $preview = app(MailHistoryAnonymizer::class)->anonymize(
        dryRun: true,
    );

    expect($preview)
        ->dryRun->toBeTrue()
        ->notificationCount->toBe(1)
        ->providerEventCount->toBe(1)
        ->scheduledMessageCount->toBe(1)
        ->and($notification->fresh()?->redacted_at)->toBeNull()
        ->and($event->fresh()?->redacted_at)->toBeNull()
        ->and($scheduled->fresh()?->redacted_at)->toBeNull();

    $result = app(MailHistoryAnonymizer::class)->anonymize();
    $notification = $notification->fresh();
    $event = $event->fresh();
    $scheduled = $scheduled->fresh();

    expect($result)
        ->dryRun->toBeFalse()
        ->notificationCount->toBe(1)
        ->providerEventCount->toBe(1)
        ->scheduledMessageCount->toBe(1)
        ->and($notification)->not->toBeNull()
        ->subject->toBeNull()
        ->from_email->toBeNull()
        ->from_name->toBeNull()
        ->to_recipients->toBe([])
        ->cc_recipients->toBeNull()
        ->bcc_recipients->toBeNull()
        ->primary_recipient_email->toBeNull()
        ->notifiable_type->toBeNull()
        ->notifiable_id->toBeNull()
        ->metadata->toBeNull()
        ->redacted_at->not->toBeNull()
        ->queue_reference->toBe($queueReference)
        ->provider_message_id->toBe($providerMessageId)
        ->and($event)->not->toBeNull()
        ->metadata->toBeNull()
        ->redacted_at->not->toBeNull()
        ->provider_message_id->toBe($providerMessageId)
        ->provider_event_id->toBe($providerEventId)
        ->and($scheduled)->not->toBeNull()
        ->payload->toBe([])
        ->to_recipients->toBe([])
        ->cc_recipients->toBeNull()
        ->bcc_recipients->toBeNull()
        ->last_error->toBeNull()
        ->notifiable_type->toBeNull()
        ->notifiable_id->toBeNull()
        ->metadata->toBeNull()
        ->redacted_at->not->toBeNull()
        ->and(MailNotification::query()->count())->toBe(1)
        ->and(MailNotificationEvent::query()->count())->toBe(1)
        ->and(ScheduledMailMessage::query()->count())->toBe(1);

    $repeated = app(MailHistoryAnonymizer::class)->anonymize();

    expect($repeated)
        ->notificationCount->toBe(0)
        ->providerEventCount->toBe(0)
        ->scheduledMessageCount->toBe(0);
});

it('keeps exact provider event retries idempotent after metadata anonymization', function () {
    $completedAt = CarbonImmutable::now('UTC')->subDays(60);
    $notification = identifyingNotification($completedAt);
    $event = identifyingProviderEvent($notification, $completedAt);
    $metadata = $event->metadata ?? [];

    app(MailHistoryAnonymizer::class)->anonymize();

    $duplicate = app(TrackingLifecycle::class)->apply(
        new VerifiedDeliveryEvent(
            provider: $event->provider,
            eventId: $event->provider_event_id,
            status: $event->normalized_type,
            occurredAt: $event->occurred_at,
            providerMessageId: $event->provider_message_id,
            correlationId: $notification->correlation_id,
            metadata: $metadata,
        ),
    );

    expect($duplicate)
        ->applied->toBeFalse()
        ->duplicate->toBeTrue()
        ->notificationId->toBe($notification->id)
        ->and(MailNotificationEvent::query()->count())->toBe(1);
});

it('still rejects changed provider event facts after metadata anonymization', function () {
    $completedAt = CarbonImmutable::now('UTC')->subDays(60);
    $notification = identifyingNotification($completedAt);
    $event = identifyingProviderEvent($notification, $completedAt);

    app(MailHistoryAnonymizer::class)->anonymize();

    expect(fn () => app(TrackingLifecycle::class)->apply(
        new VerifiedDeliveryEvent(
            provider: $event->provider,
            eventId: $event->provider_event_id,
            status: MailDeliveryStatus::Delivered,
            occurredAt: $event->occurred_at,
            providerMessageId: $event->provider_message_id,
            correlationId: $notification->correlation_id,
        ),
    ))->toThrow(
        DomainException::class,
        'conflicts with a previously processed event',
    )->and(fn () => app(TrackingLifecycle::class)->apply(
        new VerifiedDeliveryEvent(
            provider: $event->provider,
            eventId: $event->provider_event_id,
            status: $event->normalized_type,
            occurredAt: $event->occurred_at->addMicrosecond(),
            providerMessageId: $event->provider_message_id,
            correlationId: $notification->correlation_id,
        ),
    ))->toThrow(
        DomainException::class,
        'conflicts with a previously processed event',
    )->and(MailNotificationEvent::query()->count())->toBe(1);
});

it('keeps new provider events redacted after their notification was anonymized', function () {
    config()->set(
        'mail-notifications.retention.anonymization.notifications.statuses',
        ['accepted'],
    );
    $completedAt = CarbonImmutable::now('UTC')->subDays(60);
    $notification = identifyingNotification($completedAt);
    $notification->update([
        'status' => MailDeliveryStatus::Accepted,
        'accepted_at' => $completedAt,
        'failed_at' => null,
    ]);

    app(MailHistoryAnonymizer::class)->anonymize();
    $notification = MailNotification::query()->findOrFail(
        $notification->id,
    );
    $redactedAt = $notification->redacted_at;
    $eventOccurredAt = CarbonImmutable::now('UTC')->subMinute();
    $lateEvent = new VerifiedDeliveryEvent(
        provider: $notification->provider ?? 'provider-test',
        eventId: 'event-after-notification-anonymization',
        status: MailDeliveryStatus::Delivered,
        occurredAt: $eventOccurredAt,
        providerMessageId: $notification->provider_message_id,
        correlationId: $notification->correlation_id,
        metadata: [
            'recipient' => 'must-not-return@example.test',
            'provider_response' => 'must-not-return',
        ],
    );
    $lifecycle = app(TrackingLifecycle::class);
    $transition = $lifecycle->apply($lateEvent);
    $duplicate = $lifecycle->apply($lateEvent);
    $notification = MailNotification::query()->findOrFail(
        $notification->id,
    );
    $event = MailNotificationEvent::query()->where(
        'provider_event_id',
        'event-after-notification-anonymization',
    )->firstOrFail();

    expect($transition)
        ->applied->toBeTrue()
        ->currentStatus->toBe(MailDeliveryStatus::Delivered)
        ->and($duplicate)
        ->applied->toBeFalse()
        ->duplicate->toBeTrue()
        ->and($notification)
        ->status->toBe(MailDeliveryStatus::Delivered)
        ->metadata->toBeNull()
        ->redacted_at->not->toBeNull()
        ->and($notification->redacted_at?->format('U.u'))
        ->toBe($redactedAt?->format('U.u'))
        ->and($event)
        ->normalized_type->toBe(MailDeliveryStatus::Delivered)
        ->occurred_at->equalTo($eventOccurredAt)->toBeTrue()
        ->metadata->toBeNull()
        ->redacted_at->not->toBeNull()
        ->and(MailNotificationEvent::query()->count())->toBe(1);
});

it('keeps acceptance reconciliation from rehydrating anonymized metadata', function () {
    config()->set(
        'mail-notifications.retention.anonymization.notifications.statuses',
        ['pending'],
    );
    $completedAt = CarbonImmutable::now('UTC')->subDays(60);
    $notification = identifyingNotification($completedAt);
    $notification->update([
        'status' => MailDeliveryStatus::Pending,
        'failed_at' => null,
    ]);

    app(MailHistoryAnonymizer::class)->anonymize();
    $notification = MailNotification::query()->findOrFail(
        $notification->id,
    );
    $redactedAt = $notification->redacted_at;

    app(TrackingLifecycle::class)->accepted(
        new TrackingAttempt(
            id: $notification->id,
            correlationId: $notification->correlation_id,
        ),
        new ProviderAcceptance(
            messageId: new ProviderMessageId(
                provider: $notification->provider ?? 'provider-test',
                value: $notification->provider_message_id ?? 'message-test',
            ),
            metadata: ['provider_response' => 'must-not-return'],
        ),
    );

    $notification = MailNotification::query()->findOrFail(
        $notification->id,
    );

    expect($notification)
        ->status->toBe(MailDeliveryStatus::Accepted)
        ->accepted_at->not->toBeNull()
        ->metadata->toBeNull()
        ->redacted_at->not->toBeNull()
        ->and($notification->redacted_at?->format('U.u'))
        ->toBe($redactedAt?->format('U.u'));
});

it('keeps failure reconciliation from rehydrating anonymized metadata', function () {
    config()->set(
        'mail-notifications.retention.anonymization.notifications.statuses',
        ['pending'],
    );
    $completedAt = CarbonImmutable::now('UTC')->subDays(60);
    $notification = identifyingNotification($completedAt);
    $notification->update([
        'status' => MailDeliveryStatus::Pending,
        'failed_at' => null,
    ]);

    app(MailHistoryAnonymizer::class)->anonymize();
    $notification = MailNotification::query()->findOrFail(
        $notification->id,
    );
    $redactedAt = $notification->redacted_at;

    app(TrackingLifecycle::class)->failed(
        new TrackingAttempt(
            id: $notification->id,
            correlationId: $notification->correlation_id,
        ),
        new RuntimeException('must-not-return'),
    );

    $notification = MailNotification::query()->findOrFail(
        $notification->id,
    );

    expect($notification)
        ->status->toBe(MailDeliveryStatus::Failed)
        ->failed_at->not->toBeNull()
        ->metadata->toBeNull()
        ->redacted_at->not->toBeNull()
        ->and($notification->redacted_at?->format('U.u'))
        ->toBe($redactedAt?->format('U.u'));
});

it('bounds each data set independently and leaves recent or active rows untouched', function () {
    config()->set(
        'mail-notifications.retention.anonymization.batch_size',
        1,
    );
    $oldest = CarbonImmutable::now('UTC')->subDays(90);
    $newer = CarbonImmutable::now('UTC')->subDays(60);
    $first = identifyingNotification($oldest);
    identifyingProviderEvent($first, $oldest);
    $second = identifyingNotification($newer);
    identifyingProviderEvent($second, $newer);
    $scheduledFirst = identifyingScheduledMessage($oldest);
    $scheduledSecond = identifyingScheduledMessage($newer);
    $recent = identifyingNotification(
        CarbonImmutable::now('UTC')->subDay(),
    );
    $pending = identifyingScheduledMessage($oldest);
    $pending->update([
        'status' => ScheduledMailStatus::Pending,
        'failed_at' => null,
    ]);

    $result = app(MailHistoryAnonymizer::class)->anonymize(limit: 1);

    expect($result)
        ->notificationCount->toBe(1)
        ->providerEventCount->toBe(1)
        ->scheduledMessageCount->toBe(1)
        ->and($first->fresh()?->redacted_at)->not->toBeNull()
        ->and($second->fresh()?->redacted_at)->toBeNull()
        ->and($scheduledFirst->fresh()?->redacted_at)->not->toBeNull()
        ->and($scheduledSecond->fresh()?->redacted_at)->toBeNull()
        ->and($recent->fresh()?->redacted_at)->toBeNull()
        ->and($pending->fresh()?->redacted_at)->toBeNull();
});

it('is disabled by default and validates bounded configuration separately', function () {
    config()->set(
        'mail-notifications.retention.anonymization.enabled',
        false,
    );

    expect(
        fn () => app(MailHistoryAnonymizer::class)->anonymize(dryRun: true),
    )->toThrow(MailRetentionException::class, 'anonymization is disabled');

    $disabled = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'retention.anonymization');

    expect($disabled)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->message->toContain('disabled');

    config()->set(
        'mail-notifications.retention.anonymization.enabled',
        true,
    );
    config()->set(
        'mail-notifications.retention.anonymization.limit',
        10_001,
    );
    $invalid = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'retention.anonymization');

    expect($invalid)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('limit');
});

it('reports enabled anonymization boundaries through the strict doctor', function () {
    $scheduled = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'retention.anonymization');

    expect($scheduled)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->message->toContain('scheduled-message anonymization is enabled')
        ->toContain('30 day(s)')
        ->toContain('1 terminal status(es)');

    config()->set(
        'mail-notifications.retention.anonymization.scheduled_messages.enabled',
        false,
    );
    $withoutScheduled = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'retention.anonymization');

    expect($withoutScheduled)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->message->toContain('scheduled-message anonymization is disabled')
        ->toContain('30 day(s)');
});

it('rejects every malformed anonymization configuration family', function (
    string $key,
    mixed $value,
    string $message,
) {
    config()->set($key, $value);

    $check = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'retention.anonymization');

    expect($check)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain($message);
})->with([
    'enabled switch' => [
        'mail-notifications.retention.anonymization.enabled',
        'yes',
        'enabled must be a boolean',
    ],
    'notification statuses are empty' => [
        'mail-notifications.retention.anonymization.notifications.statuses',
        [],
        'statuses must be a non-empty array',
    ],
    'notification status is invalid' => [
        'mail-notifications.retention.anonymization.notifications.statuses',
        ['unknown'],
        'valid delivery status strings',
    ],
    'scheduled switch' => [
        'mail-notifications.retention.anonymization.scheduled_messages.enabled',
        'yes',
        'scheduled-message enabled must be a boolean',
    ],
    'scheduled statuses are empty' => [
        'mail-notifications.retention.anonymization.scheduled_messages.statuses',
        [],
        'statuses must be a non-empty array',
    ],
    'scheduled status is active' => [
        'mail-notifications.retention.anonymization.scheduled_messages.statuses',
        ['processing'],
        'only sent, failed, or cancelled',
    ],
    'batch size' => [
        'mail-notifications.retention.anonymization.batch_size',
        0,
        'batch size',
    ],
    'notification days' => [
        'mail-notifications.retention.anonymization.notifications.days',
        0,
        'notification days',
    ],
    'scheduled days' => [
        'mail-notifications.retention.anonymization.scheduled_messages.days',
        0,
        'scheduled-message days',
    ],
]);

it('supports explicit command previews without combining deletion', function () {
    $completedAt = CarbonImmutable::now('UTC')->subDays(60);
    $notification = identifyingNotification($completedAt);

    $this->artisan('nvl:mail-notifications:anonymize', [
        '--dry-run' => true,
        '--before' => '2026-07-01T00:00:00Z',
        '--limit' => 1,
    ])->expectsOutputToContain(
        'anonymization dry run completed; no rows were changed',
    )->expectsOutputToContain('Tracked notifications: 1')
        ->assertSuccessful();

    $this->assertModelExists($notification);
    expect($notification->fresh()?->redacted_at)->toBeNull();
});

it('returns invalid or failure for unsafe command inputs and disabled mutation', function () {
    $this->artisan('nvl:mail-notifications:anonymize', [
        '--limit' => '0',
    ])->expectsOutputToContain('must be an integer')
        ->assertExitCode(Command::INVALID);

    config()->set(
        'mail-notifications.retention.anonymization.enabled',
        false,
    );

    $this->artisan('nvl:mail-notifications:anonymize')
        ->expectsOutputToContain('anonymization is disabled')
        ->assertExitCode(Command::FAILURE);
});
