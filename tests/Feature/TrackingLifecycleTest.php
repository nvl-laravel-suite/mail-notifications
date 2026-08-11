<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Adapters\MailerSend\MailerSendAdapter;
use Nvl\MailNotifications\Contracts\SensitiveDataRedactor;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Events\MailAcceptedByProvider;
use Nvl\MailNotifications\Events\MailDeliveryStatusChanged;
use Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\MailTrackingEventDispatcher;
use Nvl\MailNotifications\Services\SensitiveStorageCodec;
use Nvl\MailNotifications\Tests\Fixtures\TestTrackable;
use Nvl\MailNotifications\ValueObjects\NotifiableReference;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\MailNotifications\ValueObjects\VerifiedWebhook;

function beginAcceptedAttempt(TrackingLifecycle $lifecycle): array
{
    $attempt = $lifecycle->begin(new PreparedMessage(
        correlationId: '4e257ee7-e867-4a5b-b454-09f7c88aa086',
        mailer: 'array',
        context: TrackingContext::forCategory('test.lifecycle'),
        from: new Recipient('sender@example.test', 'Sender'),
        to: [new Recipient('recipient@example.test')],
        subject: 'Lifecycle test',
    ));
    $lifecycle->accepted(
        $attempt,
        new ProviderAcceptance(
            new ProviderMessageId('provider-test', 'message-123'),
        ),
    );

    return [$attempt, MailNotification::query()->findOrFail($attempt->id)];
}

it('persists only registered host notifiable aliases', function () {
    $lifecycle = new DatabaseTrackingLifecycle(
        redactor: app(SensitiveDataRedactor::class),
        events: app(MailTrackingEventDispatcher::class),
        notifiableTypes: new MailNotificationNotifiableTypeRegistry(
            configuredTypes: ['test-account' => TestTrackable::class],
        ),
        sensitiveStorage: app(SensitiveStorageCodec::class),
    );
    $attempt = $lifecycle->begin(new PreparedMessage(
        correlationId: '3f24aa4b-f85c-46df-9486-f23390050ac1',
        mailer: 'array',
        context: TrackingContext::forCategory('test.notifiable')
            ->forNotifiable(new TestTrackable('account-123')),
        from: new Recipient('sender@example.test', 'Sender'),
        to: [new Recipient('recipient@example.test')],
        subject: 'Registered notifiable',
    ));

    expect(MailNotification::query()->findOrFail($attempt->id))
        ->notifiable_type->toBe('test-account')
        ->notifiable_id->toBe('account-123');
});

it('rejects an unregistered notifiable alias before persistence', function () {
    $lifecycle = new DatabaseTrackingLifecycle(
        redactor: app(SensitiveDataRedactor::class),
        events: app(MailTrackingEventDispatcher::class),
        notifiableTypes: new MailNotificationNotifiableTypeRegistry,
        sensitiveStorage: app(SensitiveStorageCodec::class),
    );
    $message = new PreparedMessage(
        correlationId: '6d8422e1-9d5e-49af-8e29-20584ddb15e7',
        mailer: 'array',
        context: new TrackingContext(
            category: 'test.notifiable',
            notifiable: new NotifiableReference('missing-alias', 'account-123'),
        ),
        from: new Recipient('sender@example.test', 'Sender'),
        to: [new Recipient('recipient@example.test')],
        subject: 'Missing notifiable',
    );

    expect(fn () => $lifecycle->begin($message))
        ->toThrow(
            DomainException::class,
            'notifiable type [missing-alias] is not registered',
        )
        ->and(MailNotification::query()->count())->toBe(0);
});

it('applies verified delivery transitions monotonically', function () {
    $lifecycle = app(TrackingLifecycle::class);
    [$attempt] = beginAcceptedAttempt($lifecycle);
    $deliveredAt = CarbonImmutable::parse('2026-07-29T10:00:00Z');
    $result = $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-delivered',
        status: MailDeliveryStatus::Delivered,
        occurredAt: $deliveredAt,
        providerMessageId: 'message-123',
    ));

    $notification = MailNotification::query()->findOrFail($attempt->id);

    expect($result)
        ->applied->toBeTrue()
        ->previousStatus->toBe(MailDeliveryStatus::Accepted)
        ->currentStatus->toBe(MailDeliveryStatus::Delivered)
        ->and($notification->status)->toBe(MailDeliveryStatus::Delivered)
        ->and($notification->delivered_at?->equalTo($deliveredAt))->toBeTrue();
});

it('allows MailerSend delivery to advance after a soft bounce', function () {
    $lifecycle = app(TrackingLifecycle::class);
    $attempt = $lifecycle->begin(new PreparedMessage(
        correlationId: 'c33e17d9-2da5-47b8-bcce-24a686659b07',
        mailer: 'mailersend',
        context: TrackingContext::forCategory('test.mailersend-soft-bounce'),
        from: new Recipient('sender@example.test', 'Sender'),
        to: [new Recipient('recipient@example.test')],
        subject: 'Soft bounce lifecycle test',
    ));
    $lifecycle->accepted(
        $attempt,
        new ProviderAcceptance(
            new ProviderMessageId('mailersend', 'message-soft-bounce'),
        ),
    );
    $adapter = app(MailerSendAdapter::class);
    $softBounce = $adapter->normalize(new VerifiedWebhook('mailersend', [
        'type' => 'activity.soft_bounced',
        'created_at' => CarbonImmutable::now('UTC')
            ->subMinutes(2)
            ->toIso8601String(),
        'data' => [
            'id' => 'event-soft-bounce',
            'message_id' => 'message-soft-bounce',
        ],
    ]));
    $delivered = $adapter->normalize(new VerifiedWebhook('mailersend', [
        'type' => 'activity.delivered',
        'created_at' => CarbonImmutable::now('UTC')
            ->subMinute()
            ->toIso8601String(),
        'data' => [
            'id' => 'event-after-soft-bounce',
            'message_id' => 'message-soft-bounce',
        ],
    ]));

    expect($softBounce)->toBeInstanceOf(VerifiedDeliveryEvent::class)
        ->status->toBe(MailDeliveryStatus::Delayed)
        ->and($delivered)->toBeInstanceOf(VerifiedDeliveryEvent::class);

    $lifecycle->apply($softBounce);
    $result = $lifecycle->apply($delivered);

    expect($result)
        ->applied->toBeTrue()
        ->previousStatus->toBe(MailDeliveryStatus::Delayed)
        ->currentStatus->toBe(MailDeliveryStatus::Delivered)
        ->and(MailNotification::query()->findOrFail($attempt->id)->status)
        ->toBe(MailDeliveryStatus::Delivered);
});

it('keeps lifecycle event listeners observational during webhook processing', function () {
    Event::listen(
        MailDeliveryStatusChanged::class,
        static fn (): never => throw new RuntimeException('Host listener failed.'),
    );
    $lifecycle = app(TrackingLifecycle::class);
    [$attempt] = beginAcceptedAttempt($lifecycle);
    $result = $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-listener-failure',
        status: MailDeliveryStatus::Delivered,
        occurredAt: CarbonImmutable::parse('2026-07-29T10:00:00Z'),
        providerMessageId: 'message-123',
    ));

    expect($result)
        ->applied->toBeTrue()
        ->and(MailNotification::query()->findOrFail($attempt->id)->status)
        ->toBe(MailDeliveryStatus::Delivered);
});

it('treats duplicate provider events as no-ops', function () {
    $lifecycle = app(TrackingLifecycle::class);
    beginAcceptedAttempt($lifecycle);
    $event = new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-duplicate',
        status: MailDeliveryStatus::Delivered,
        occurredAt: CarbonImmutable::parse('2026-07-29T10:00:00Z'),
        providerMessageId: 'message-123',
        metadata: [
            'delivery' => [
                'region' => 'eu',
                'attempt' => 1,
            ],
        ],
    );

    expect($lifecycle->apply($event)->applied)->toBeTrue();

    $duplicate = $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-duplicate',
        status: MailDeliveryStatus::Delivered,
        occurredAt: CarbonImmutable::parse('2026-07-29T10:00:00Z'),
        providerMessageId: 'message-123',
        metadata: [
            'delivery' => [
                'attempt' => 1,
                'region' => 'eu',
            ],
        ],
    ));

    expect($duplicate)
        ->applied->toBeFalse()
        ->duplicate->toBeTrue()
        ->and(MailNotificationEvent::query()->count())->toBe(1);
});

it('rejects conflicting reuse of a provider event identifier', function () {
    $lifecycle = app(TrackingLifecycle::class);
    beginAcceptedAttempt($lifecycle);
    $occurredAt = CarbonImmutable::parse('2026-07-29T10:00:00Z');

    $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-conflict',
        status: MailDeliveryStatus::Delivered,
        occurredAt: $occurredAt,
        providerMessageId: 'message-123',
    ));

    expect(fn () => $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-conflict',
        status: MailDeliveryStatus::Opened,
        occurredAt: $occurredAt->addMinute(),
        providerMessageId: 'message-123',
    )))->toThrow(DomainException::class, 'conflicts with a previously processed event');

    expect(MailNotificationEvent::query()->count())->toBe(1);
});

it('rejects duplicate provider events whose persisted metadata changes', function () {
    $lifecycle = app(TrackingLifecycle::class);
    beginAcceptedAttempt($lifecycle);
    $occurredAt = CarbonImmutable::parse('2026-07-29T10:00:00Z');

    $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-metadata-conflict',
        status: MailDeliveryStatus::Delivered,
        occurredAt: $occurredAt,
        providerMessageId: 'message-123',
        metadata: ['region' => 'eu'],
    ));

    expect(fn () => $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-metadata-conflict',
        status: MailDeliveryStatus::Delivered,
        occurredAt: $occurredAt,
        providerMessageId: 'message-123',
        metadata: ['region' => 'us'],
    )))->toThrow(
        DomainException::class,
        'conflicts with a previously processed event',
    );

    expect(MailNotificationEvent::query()->count())->toBe(1);
});

it('persists stale events without moving lifecycle state backward', function () {
    $lifecycle = app(TrackingLifecycle::class);
    [$attempt] = beginAcceptedAttempt($lifecycle);
    $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-delivered',
        status: MailDeliveryStatus::Delivered,
        occurredAt: CarbonImmutable::parse('2026-07-29T10:00:00Z'),
        providerMessageId: 'message-123',
    ));
    $result = $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-stale',
        status: MailDeliveryStatus::Delayed,
        occurredAt: CarbonImmutable::parse('2026-07-29T09:00:00Z'),
        providerMessageId: 'message-123',
    ));

    expect($result)
        ->applied->toBeFalse()
        ->currentStatus->toBe(MailDeliveryStatus::Delivered)
        ->and(MailNotification::query()->findOrFail($attempt->id)->status)
        ->toBe(MailDeliveryStatus::Delivered)
        ->and(MailNotificationEvent::query()->count())->toBe(2);
});

it('does not replace terminal provider failures', function () {
    $lifecycle = app(TrackingLifecycle::class);
    [$attempt] = beginAcceptedAttempt($lifecycle);
    $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-bounced',
        status: MailDeliveryStatus::Bounced,
        occurredAt: CarbonImmutable::parse('2026-07-29T10:00:00Z'),
        providerMessageId: 'message-123',
    ));
    $result = $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-delivered-late',
        status: MailDeliveryStatus::Delivered,
        occurredAt: CarbonImmutable::parse('2026-07-29T11:00:00Z'),
        providerMessageId: 'message-123',
    ));

    expect($result)
        ->applied->toBeFalse()
        ->currentStatus->toBe(MailDeliveryStatus::Bounced)
        ->and(MailNotification::query()->findOrFail($attempt->id)->status)
        ->toBe(MailDeliveryStatus::Bounced);
});

it('preserves advanced engagement when provider events arrive out of order', function () {
    $lifecycle = app(TrackingLifecycle::class);
    [$attempt] = beginAcceptedAttempt($lifecycle);
    $clickedAt = CarbonImmutable::parse('2026-07-29T10:05:00Z');
    $deliveredAt = CarbonImmutable::parse('2026-07-29T10:00:00Z');
    $clicked = $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-clicked-first',
        status: MailDeliveryStatus::Clicked,
        occurredAt: $clickedAt,
        providerMessageId: 'message-123',
    ));
    $delivered = $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-delivered-later',
        status: MailDeliveryStatus::Delivered,
        occurredAt: $deliveredAt,
        providerMessageId: 'message-123',
    ));

    expect($clicked)
        ->applied->toBeTrue()
        ->currentStatus->toBe(MailDeliveryStatus::Clicked)
        ->and($delivered)
        ->applied->toBeFalse()
        ->currentStatus->toBe(MailDeliveryStatus::Clicked)
        ->and(MailNotification::query()->findOrFail($attempt->id))
        ->status->toBe(MailDeliveryStatus::Clicked)
        ->provider_occurred_at?->equalTo($clickedAt)->toBeTrue()
        ->delivered_at?->equalTo($deliveredAt)->toBeTrue();
});

it('does not move delayed provider state backward when transport acceptance completes', function () {
    Event::fake([MailAcceptedByProvider::class]);
    $lifecycle = app(TrackingLifecycle::class);
    $attempt = $lifecycle->begin(new PreparedMessage(
        correlationId: '8a031165-23bb-4b38-8b20-552920ec97df',
        mailer: 'array',
        context: TrackingContext::forCategory('test.acceptance-race'),
        from: new Recipient('sender@example.test', 'Sender'),
        to: [new Recipient('recipient@example.test')],
        subject: 'Acceptance race',
    ));
    $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-delayed-first',
        status: MailDeliveryStatus::Delayed,
        occurredAt: CarbonImmutable::parse('2026-07-29T10:00:00Z'),
        correlationId: $attempt->correlationId,
    ));

    $lifecycle->accepted(
        $attempt,
        new ProviderAcceptance(
            new ProviderMessageId('provider-test', 'message-race'),
        ),
    );

    $notification = MailNotification::query()->findOrFail($attempt->id);

    expect($notification)
        ->status->toBe(MailDeliveryStatus::Delayed)
        ->provider_message_id->toBe('message-race')
        ->accepted_at->not->toBeNull();

    Event::assertDispatched(
        MailAcceptedByProvider::class,
        static fn (MailAcceptedByProvider $event): bool => $event->attempt->id
            === $notification->id,
    );
});

it('does not replace an established provider identity during repeated acceptance', function (
    ProviderMessageId $conflictingMessageId,
    string $expectedMessage,
) {
    $lifecycle = app(TrackingLifecycle::class);
    [$attempt] = beginAcceptedAttempt($lifecycle);

    expect(fn () => $lifecycle->accepted(
        $attempt,
        new ProviderAcceptance($conflictingMessageId),
    ))->toThrow(DomainException::class, $expectedMessage);

    expect(MailNotification::query()->findOrFail($attempt->id))
        ->provider->toBe('provider-test')
        ->provider_message_id->toBe('message-123');
})->with([
    'provider conflict' => [
        new ProviderMessageId('another-provider', 'message-123'),
        'does not match the tracked mail provider',
    ],
    'message conflict' => [
        new ProviderMessageId('provider-test', 'another-message'),
        'does not match the tracked provider message',
    ],
]);

it('emits provider acceptance once for an idempotent repeated acceptance', function () {
    Event::fake([MailAcceptedByProvider::class]);
    $lifecycle = app(TrackingLifecycle::class);
    $attempt = $lifecycle->begin(new PreparedMessage(
        correlationId: 'd3bc36ee-d210-461e-b728-646f5176c285',
        mailer: 'array',
        context: TrackingContext::forCategory('test.repeated-acceptance'),
        from: new Recipient('sender@example.test', 'Sender'),
        to: [new Recipient('recipient@example.test')],
        subject: 'Repeated acceptance',
    ));
    $acceptance = new ProviderAcceptance(
        new ProviderMessageId('provider-test', 'message-repeat'),
    );

    $lifecycle->accepted($attempt, $acceptance);
    $lifecycle->accepted($attempt, $acceptance);

    Event::assertDispatchedTimes(MailAcceptedByProvider::class, 1);
});

it('preserves subsecond provider event identity for retries and conflicts', function () {
    $lifecycle = app(TrackingLifecycle::class);
    beginAcceptedAttempt($lifecycle);
    $occurredAt = CarbonImmutable::parse('2026-07-29T10:00:00.123456Z');
    $event = new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-subsecond-conflict',
        status: MailDeliveryStatus::Delivered,
        occurredAt: $occurredAt,
        providerMessageId: 'message-123',
    );

    $lifecycle->apply($event);
    $duplicate = $lifecycle->apply($event);
    $persisted = MailNotificationEvent::query()
        ->where('provider_event_id', 'event-subsecond-conflict')
        ->firstOrFail();

    expect($duplicate)
        ->applied->toBeFalse()
        ->duplicate->toBeTrue()
        ->and($persisted->occurred_at->format('U.u'))
        ->toBe($occurredAt->format('U.u'))
        ->and(fn () => $lifecycle->apply(new VerifiedDeliveryEvent(
            provider: 'provider-test',
            eventId: 'event-subsecond-conflict',
            status: MailDeliveryStatus::Delivered,
            occurredAt: $occurredAt->addMicrosecond(),
            providerMessageId: 'message-123',
        )))->toThrow(
            DomainException::class,
            'conflicts with a previously processed event',
        );
});

it('rejects a provider event that conflicts with accepted message identity', function () {
    $lifecycle = app(TrackingLifecycle::class);
    [$attempt] = beginAcceptedAttempt($lifecycle);

    expect(fn () => $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'another-provider',
        eventId: 'event-provider-conflict',
        status: MailDeliveryStatus::Delivered,
        occurredAt: CarbonImmutable::parse('2026-07-29T10:00:00Z'),
        correlationId: $attempt->correlationId,
    )))->toThrow(DomainException::class);

    expect(fn () => $lifecycle->apply(new VerifiedDeliveryEvent(
        provider: 'provider-test',
        eventId: 'event-message-conflict',
        status: MailDeliveryStatus::Delivered,
        occurredAt: CarbonImmutable::parse('2026-07-29T10:00:00Z'),
        providerMessageId: 'another-message',
        correlationId: $attempt->correlationId,
    )))->toThrow(DomainException::class);

    expect(MailNotification::query()->findOrFail($attempt->id)->status)
        ->toBe(MailDeliveryStatus::Accepted)
        ->and(MailNotificationEvent::query()->count())->toBe(0);
});

it('detects multiple provider identity candidates in a broken schema', function () {
    $connectionName = 'ambiguous-tracking-lifecycle-test';
    $originalConnection = config(
        'mail-notifications.storage.connection',
    );
    config()->set('database.connections.'.$connectionName, [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($connectionName);
    config()->set('mail-notifications.storage.connection', $connectionName);
    $migration = require dirname(__DIR__, 2)
        .'/database/migrations/2026_07_29_000000_create_mail_notification_tables.php';
    $migration->up();
    Schema::connection($connectionName)->table(
        MailNotificationsTables::Notifications,
        static function (Blueprint $table): void {
            $table->dropUnique(
                'mail_notifications_provider_message_unique',
            );
        },
    );

    try {
        foreach ([
            [
                'id' => '0dc42086-9ed2-4ddf-8b46-c220e93c38b3',
                'correlation_id' => '5210a631-9fb0-4989-9543-b5b73db76e3b',
            ],
            [
                'id' => '8dc6f043-8fda-44cb-b7ca-a727803bbfd2',
                'correlation_id' => '294383ef-079a-4637-8cde-f913ae302b05',
            ],
        ] as $identity) {
            MailNotification::query()->create([
                ...$identity,
                'mailer' => 'array',
                'provider' => 'provider-test',
                'provider_message_id' => 'ambiguous-message',
                'status' => MailDeliveryStatus::Accepted,
                'message_category' => 'test.ambiguous-identity',
                'to_recipients' => [[
                    'email' => 'recipient@example.test',
                    'name' => null,
                ]],
                'status_changed_at' => CarbonImmutable::now('UTC'),
            ]);
        }

        expect(fn () => app(DatabaseTrackingLifecycle::class)->apply(
            new VerifiedDeliveryEvent(
                provider: 'provider-test',
                eventId: 'event-ambiguous-identity',
                status: MailDeliveryStatus::Delivered,
                occurredAt: CarbonImmutable::now('UTC'),
                providerMessageId: 'ambiguous-message',
            ),
        ))->toThrow(
            AmbiguousDeliveryEventException::class,
            'Multiple tracked mail notifications',
        )
            ->and(MailNotification::query()->count())->toBe(2)
            ->and(MailNotificationEvent::query()->count())->toBe(0);
    } finally {
        config()->set(
            'mail-notifications.storage.connection',
            $originalConnection,
        );
        DB::purge($connectionName);
    }
});
