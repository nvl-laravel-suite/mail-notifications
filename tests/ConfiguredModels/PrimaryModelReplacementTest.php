<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Exceptions\ScheduledMailException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Ownership\MailResourceRegistrar;
use Nvl\MailNotifications\Services\MailHistory;
use Nvl\MailNotifications\Services\MailModelRegistry;
use Nvl\MailNotifications\Services\ScheduledMailClaimer;
use Nvl\MailNotifications\Services\ScheduledMailProcessor;
use Nvl\MailNotifications\Services\ScheduledMailScheduler;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Tests\Fixtures\ExtendedMailNotification;
use Nvl\MailNotifications\Tests\Fixtures\ExtendedScheduledMailMessage;
use Nvl\MailNotifications\Tests\Fixtures\ScheduledTestFactory;
use Nvl\MailNotifications\Tests\Fixtures\TrackedMail;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

class MailModelQueueProbe
{
    use SerializesModels;

    public function __construct(public MailNotification $notification, public ScheduledMailMessage $scheduled) {}
}

class MailDivergentConnection extends ExtendedMailNotification
{
    protected $connection = 'testing';
}

class MailDivergentTable extends ExtendedScheduledMailMessage
{
    public function getTable(): string
    {
        return 'foreign_schedules';
    }
}

class MailMissingCast extends ExtendedScheduledMailMessage
{
    protected function casts(): array
    {
        return ['status' => 'string'];
    }
}

function configuredMailRequest(): ScheduleMailData
{
    return new ScheduleMailData(
        factoryAlias: 'test.scheduled', payloadVersion: 1, payload: ['body' => 'Body'],
        recipients: new ScheduledRecipients(to: [new Recipient('recipient@example.test')]),
        scheduledFor: CarbonImmutable::now('UTC')->subSecond(),
    );
}

beforeEach(function (): void {
    config()->set('nvl-mail-notifications.scheduling.enabled', true);
    app()->instance(ScheduledMessageFactoryRegistry::class, new ScheduledMessageFactoryRegistry([new ScheduledTestFactory]));
});

it('uses configured mail roots through transport tracking history relations factories and queued restoration', function (): void {
    Mail::to('recipient@example.test')->send(new TrackedMail);
    $notification = MailModelRegistry::notificationsQuery()->sole();
    $event = MailNotificationEvent::factory()->create(['mail_notification_id' => $notification->id]);
    $seen = null;
    config()->set('nvl-mail-notifications.management.authorization.callback', function ($ability, $actor, $model) use (&$seen): bool {
        $seen = $model;

        return true;
    });
    $history = app(MailHistory::class)->get(new GenericUser(['id' => 'reader']), $notification->id);

    expect($notification)->toBeInstanceOf(ExtendedMailNotification::class)
        ->and($notification->getConnection()->getName())->toBe('mail-root')
        ->and($notification->displayCategory())->toBe('TEST.MESSAGE')
        ->and($seen)->toBeInstanceOf(ExtendedMailNotification::class)
        ->and($history['id'])->toBe($notification->id)
        ->and($event->mailNotification)->toBeInstanceOf(ExtendedMailNotification::class)
        ->and($notification->providerEvents->sole()->id)->toBe($event->id)
        ->and(Relation::getMorphedModel(MailNotification::class))->toBe(ExtendedMailNotification::class)
        ->and(Relation::getMorphedModel(ScheduledMailMessage::class))->toBe(ExtendedScheduledMailMessage::class)
        ->and(app(OwnershipResourceRegistry::class)->get('mail.notifications')->model)->toBe(ExtendedMailNotification::class)
        ->and(app(OwnershipResourceRegistry::class)->get('mail.scheduled')->model)->toBe(ExtendedScheduledMailMessage::class)
        ->and(app(OwnershipResourceRegistry::class)->get('mail.events')->model)->toBe(MailNotificationEvent::class)
        ->and(MailNotification::factory()->create())->toBeInstanceOf(ExtendedMailNotification::class);

    $scheduled = app(ScheduledMailScheduler::class)->schedule(configuredMailRequest());
    $restored = unserialize(serialize(new MailModelQueueProbe($notification, $scheduled)));
    expect($scheduled)->toBeInstanceOf(ExtendedScheduledMailMessage::class)
        ->and($scheduled->displayFactory())->toBe('TEST.SCHEDULED')
        ->and($restored->notification)->toBeInstanceOf(ExtendedMailNotification::class)
        ->and($restored->scheduled)->toBeInstanceOf(ExtendedScheduledMailMessage::class)
        ->and($restored->scheduled->getConnection()->getName())->toBe('mail-root')
        ->and(ScheduledMailMessage::factory()->create())->toBeInstanceOf(ExtendedScheduledMailMessage::class);

    $owned = new OwnershipResourceRegistry;
    app(MailResourceRegistrar::class)->register($owned, true);
    $owned->freeze();
    expect($owned->get('mail.notifications')->model)->toBe(ExtendedMailNotification::class)
        ->and($owned->get('mail.scheduled')->model)->toBe(ExtendedScheduledMailMessage::class)
        ->and($owned->get('mail.notifications')->ownership->strategy)->toBe('captured')
        ->and($owned->get('mail.scheduled')->ownership->strategy)->toBe('captured')
        ->and($owned->get('mail.events')->ownership->strategy)->toBe('inherited')
        ->and($owned->get('mail.events')->parents())->toBe(['mail.notifications']);

    config()->set('nvl-mail-notifications.models.notification', MailNotification::class);
    config()->set('nvl-mail-notifications.models.scheduled_message', ScheduledMailMessage::class);
    expect(app(MailModelRegistry::class)->notificationClass())->toBe(ExtendedMailNotification::class)
        ->and(app(MailModelRegistry::class)->scheduledClass())->toBe(ExtendedScheduledMailMessage::class);
});

it('keeps configured schedule rollback and source transaction refusal before native claim or transport', function (): void {
    $scheduler = app(ScheduledMailScheduler::class);
    $connection = MailModelRegistry::scheduled()->getConnection();
    $connection->beginTransaction();
    try {
        $message = $scheduler->schedule(configuredMailRequest());
        expect($message)->toBeInstanceOf(ExtendedScheduledMailMessage::class);
    } finally {
        $connection->rollBack();
    }
    expect(MailModelRegistry::scheduledQuery()->count())->toBe(0);

    $message = $scheduler->schedule(configuredMailRequest());
    $connection->beginTransaction();
    try {
        expect(fn () => app(ScheduledMailClaimer::class)->claim())->toThrow(ScheduledMailException::class, 'existing database transaction')
            ->and(fn () => app(ScheduledMailProcessor::class)->process())->toThrow(ScheduledMailException::class, 'existing database transaction');
    } finally {
        $connection->rollBack();
    }
    expect($message->fresh()->status)->toBe(ScheduledMailStatus::Pending)
        ->and($message->fresh()->attempts)->toBe(0)
        ->and(app(ScheduledMailProcessor::class)->process())->toBe(1)
        ->and($message->fresh()->status)->toBe(ScheduledMailStatus::Sent);
});

it('rejects incompatible mail storage or required casts before native effects', function (string $key, string $class, string $reason): void {
    $configuration = new Repository(['nvl-mail-notifications' => ['models' => [$key => $class]]]);
    $registry = new MailModelRegistry($configuration, new OwnerRegistry($configuration));
    $map = Relation::morphMap();
    try {
        Relation::morphMap([], false);
        expect(fn () => $key === 'notification' ? $registry->notificationClass() : $registry->scheduledClass())
            ->toThrow(OwnershipConfigurationInvalid::class, $reason);
    } finally {
        Relation::morphMap($map, false);
    }
    expect(MailModelRegistry::notificationsQuery()->count())->toBe(0)
        ->and(MailModelRegistry::scheduledQuery()->count())->toBe(0);
})->with([
    ['notification', MailDivergentConnection::class, 'share the package storage'],
    ['scheduled_message', MailDivergentTable::class, 'share the package storage'],
    ['scheduled_message', MailMissingCast::class, 'required package casts'],
]);
