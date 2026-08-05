<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-30 12:00:00 UTC');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('persists host-neutral package model factory defaults', function (): void {
    $notification = MailNotification::factory()->create();
    $event = MailNotificationEvent::factory()->create();
    $scheduledMessage = ScheduledMailMessage::factory()->create();

    expect($notification)
        ->toBeInstanceOf(MailNotification::class)
        ->id->toBe($notification->correlation_id)
        ->status->toBe(MailDeliveryStatus::Pending)
        ->subject->toBeNull()
        ->notifiable_type->toBeNull()
        ->notifiable_id->toBeNull()
        ->metadata->toBe(['source' => 'factory'])
        ->and($event)
        ->toBeInstanceOf(MailNotificationEvent::class)
        ->normalized_type->toBe(MailDeliveryStatus::Delivered)
        ->provider_message_id->toBeNull()
        ->metadata->toBe(['source' => 'factory'])
        ->and($event->mailNotification)
        ->toBeInstanceOf(MailNotification::class)
        ->status->toBe(MailDeliveryStatus::Delivered)
        ->and($scheduledMessage)
        ->toBeInstanceOf(ScheduledMailMessage::class)
        ->status->toBe(ScheduledMailStatus::Pending)
        ->payload->toBe([
            'fixture_reference' => 'mail-notifications-factory',
        ])
        ->notifiable_type->toBeNull()
        ->notifiable_id->toBeNull()
        ->claim_token->toBeNull();

    $this->assertModelExists($notification);
    $this->assertModelExists($event);
    $this->assertModelExists($event->mailNotification);
    $this->assertModelExists($scheduledMessage);
});

it('persists and restores package timestamps in UTC with microseconds', function (): void {
    $previousTimezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Sofia');
    config()->set('app.timezone', 'Europe/Sofia');
    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-07-30 15:00:00.123456 Europe/Sofia'),
    );

    try {
        $notification = MailNotification::factory()->create();
        $event = MailNotificationEvent::factory()->create();
        $scheduledMessage = ScheduledMailMessage::factory()->create();
        $expected = CarbonImmutable::now('UTC')->format('U.u');

        expect($notification->freshTimestamp()->timezoneName)
            ->toBe('UTC')
            ->and($event->freshTimestamp()->timezoneName)
            ->toBe('UTC')
            ->and($scheduledMessage->freshTimestamp()->timezoneName)
            ->toBe('UTC')
            ->and($notification->created_at->timezoneName)
            ->toBe('UTC')
            ->and($notification->created_at->format('U.u'))
            ->toBe($expected)
            ->and($event->created_at->timezoneName)
            ->toBe('UTC')
            ->and($event->created_at->format('U.u'))
            ->toBe($expected)
            ->and($scheduledMessage->created_at->timezoneName)
            ->toBe('UTC')
            ->and($scheduledMessage->created_at->format('U.u'))
            ->toBe($expected)
            ->and($notification->getDateFormat())
            ->toBe('Y-m-d H:i:s.u')
            ->and($event->getDateFormat())
            ->toBe('Y-m-d H:i:s.u')
            ->and($scheduledMessage->getDateFormat())
            ->toBe('Y-m-d H:i:s.u');
    } finally {
        date_default_timezone_set($previousTimezone);
    }
});

it('provides coherent notification lifecycle factory states', function (
    string $state,
    MailDeliveryStatus $status,
    bool $hasProvider,
    bool $hasAcceptance,
    bool $hasDelivery,
    bool $hasFailure,
): void {
    $notification = MailNotification::factory()->{$state}()->create();

    expect($notification)
        ->status->toBe($status)
        ->status_changed_at->not->toBeNull()
        ->and($notification->provider !== null)->toBe($hasProvider)
        ->and($notification->provider_message_id !== null)->toBe($hasProvider)
        ->and($notification->provider_occurred_at !== null)->toBe($hasProvider)
        ->and($notification->accepted_at !== null)->toBe($hasAcceptance)
        ->and($notification->delivered_at !== null)->toBe($hasDelivery)
        ->and($notification->failed_at !== null)->toBe($hasFailure);

    if ($notification->accepted_at !== null
        && $notification->delivered_at !== null) {
        expect(
            $notification->accepted_at->lessThanOrEqualTo(
                $notification->delivered_at,
            ),
        )->toBeTrue();
    }
})->with([
    'pending' => [
        'pending',
        MailDeliveryStatus::Pending,
        false,
        false,
        false,
        false,
    ],
    'accepted' => [
        'accepted',
        MailDeliveryStatus::Accepted,
        true,
        true,
        false,
        false,
    ],
    'delayed' => [
        'delayed',
        MailDeliveryStatus::Delayed,
        true,
        true,
        false,
        false,
    ],
    'delivered' => [
        'delivered',
        MailDeliveryStatus::Delivered,
        true,
        true,
        true,
        false,
    ],
    'opened' => [
        'opened',
        MailDeliveryStatus::Opened,
        true,
        true,
        true,
        false,
    ],
    'clicked' => [
        'clicked',
        MailDeliveryStatus::Clicked,
        true,
        true,
        true,
        false,
    ],
    'bounced' => [
        'bounced',
        MailDeliveryStatus::Bounced,
        true,
        true,
        false,
        true,
    ],
    'complained' => [
        'complained',
        MailDeliveryStatus::Complained,
        true,
        true,
        true,
        true,
    ],
    'rejected' => [
        'rejected',
        MailDeliveryStatus::Rejected,
        true,
        false,
        false,
        true,
    ],
    'failed' => [
        'failed',
        MailDeliveryStatus::Failed,
        false,
        false,
        false,
        true,
    ],
    'unsubscribed' => [
        'unsubscribed',
        MailDeliveryStatus::Unsubscribed,
        true,
        true,
        true,
        true,
    ],
]);

it('provides an event factory state for every normalized delivery status', function (
    string $state,
    MailDeliveryStatus $status,
): void {
    $event = MailNotificationEvent::factory()->{$state}()->create();

    expect($event)
        ->normalized_type->toBe($status)
        ->occurred_at->not->toBeNull()
        ->processed_at->not->toBeNull();
})->with([
    'pending' => ['pending', MailDeliveryStatus::Pending],
    'accepted' => ['accepted', MailDeliveryStatus::Accepted],
    'delayed' => ['delayed', MailDeliveryStatus::Delayed],
    'delivered' => ['delivered', MailDeliveryStatus::Delivered],
    'opened' => ['opened', MailDeliveryStatus::Opened],
    'clicked' => ['clicked', MailDeliveryStatus::Clicked],
    'bounced' => ['bounced', MailDeliveryStatus::Bounced],
    'complained' => ['complained', MailDeliveryStatus::Complained],
    'rejected' => ['rejected', MailDeliveryStatus::Rejected],
    'failed' => ['failed', MailDeliveryStatus::Failed],
    'unsubscribed' => ['unsubscribed', MailDeliveryStatus::Unsubscribed],
]);

it('provides coherent scheduled lifecycle and retry factory states', function (): void {
    $pending = ScheduledMailMessage::factory()->pending()->create();
    $due = ScheduledMailMessage::factory()->due()->create();
    $processing = ScheduledMailMessage::factory()->processing()->create();
    $retrying = ScheduledMailMessage::factory()->retrying()->create();
    $sent = ScheduledMailMessage::factory()->sent()->create();
    $failed = ScheduledMailMessage::factory()->failed()->create();
    $cancelled = ScheduledMailMessage::factory()->cancelled()->create();

    expect($pending)
        ->status->toBe(ScheduledMailStatus::Pending)
        ->attempts->toBe(0)
        ->and($due)
        ->status->toBe(ScheduledMailStatus::Pending)
        ->available_at->toEqual(CarbonImmutable::now('UTC'))
        ->and($processing)
        ->status->toBe(ScheduledMailStatus::Processing)
        ->attempts->toBe(1)
        ->claim_token->not->toBeNull()
        ->locked_until->not->toBeNull()
        ->and($retrying)
        ->status->toBe(ScheduledMailStatus::Pending)
        ->attempts->toBe(1)
        ->claim_token->toBeNull()
        ->last_error->toBe(RuntimeException::class)
        ->and($sent)
        ->status->toBe(ScheduledMailStatus::Sent)
        ->sent_at->not->toBeNull()
        ->claim_token->toBeNull()
        ->and($failed)
        ->status->toBe(ScheduledMailStatus::Failed)
        ->attempts->toBe($failed->max_attempts)
        ->failed_at->not->toBeNull()
        ->last_error->toBe(RuntimeException::class)
        ->and($cancelled)
        ->status->toBe(ScheduledMailStatus::Cancelled)
        ->cancelled_at->not->toBeNull()
        ->claim_token->toBeNull();
});
