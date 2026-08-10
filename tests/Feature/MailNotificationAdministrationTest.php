<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Nvl\MailNotifications\Actions\GetMailNotificationStatisticsAction;
use Nvl\MailNotifications\Actions\ListMailNotificationsAction;
use Nvl\MailNotifications\Actions\ShowMailNotificationAction;
use Nvl\MailNotifications\Actions\SuggestMailNotificationsAction;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\MailNotificationReadAbility;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;

function mailNotificationAdministrator(): GenericUser
{
    return new GenericUser([
        'id' => 'administrator-1',
        'email' => 'administrator@example.test',
    ]);
}

beforeEach(function (): void {
    config()->set(
        'mail-notifications.management.authorization.callback',
        static fn (): bool => true,
    );
});

it('denies administrative reads until the host supplies an authorization decision', function (): void {
    config()->set('mail-notifications.management.authorization.callback', null);

    expect(fn () => app(ListMailNotificationsAction::class)->execute(
        mailNotificationAdministrator(),
        new MailNotificationReadQuery,
    ))->toThrow(AuthorizationException::class);
});

it('returns bounded filtered pages without sensitive arrays or metadata', function (): void {
    $matching = MailNotification::factory()->delivered()->create([
        'subject' => 'Quarterly account report',
        'mailer' => 'mailersend',
        'message_category' => 'account.report',
        'metadata' => ['private_token' => 'must-not-leak'],
        'bcc_recipients' => [[
            'email' => 'hidden@example.test',
            'name' => null,
        ]],
    ]);
    MailNotification::factory()->failed()->create([
        'subject' => 'Different delivery',
    ]);
    config()->set('mail-notifications.management.maximum_per_page', 1);

    $page = app(ListMailNotificationsAction::class)->execute(
        mailNotificationAdministrator(),
        new MailNotificationReadQuery(
            search: 'Quarterly',
            status: MailDeliveryStatus::Delivered,
            mailer: 'mailersend',
            messageCategory: 'account.report',
            perPage: 50,
        ),
    );
    $payload = $page->toArray();

    expect($page->total)->toBe(1)
        ->and($page->perPage)->toBe(1)
        ->and($page->items[0]->id)->toBe($matching->id)
        ->and($payload['data'][0])->not->toHaveKeys([
            'to_recipients',
            'cc_recipients',
            'bcc_recipients',
            'metadata',
        ]);
});

it('authorizes show statistics and suggestion reads independently', function (): void {
    $abilities = [];
    config()->set(
        'mail-notifications.management.authorization.callback',
        static function (MailNotificationReadAbility $ability) use (&$abilities): bool {
            $abilities[] = $ability;

            return true;
        },
    );
    $notification = MailNotification::factory()->delivered()->create([
        'subject' => 'Delivery receipt',
        'primary_recipient_email' => 'recipient@example.test',
    ]);
    MailNotification::factory()->failed()->create([
        'subject' => 'Failed receipt',
    ]);
    MailNotificationEvent::factory()->opened()->create([
        'mail_notification_id' => $notification->id,
        'metadata' => ['raw_webhook' => 'must-not-leak'],
    ]);

    $shown = app(ShowMailNotificationAction::class)->execute(
        mailNotificationAdministrator(),
        $notification->id,
    );
    $statistics = app(GetMailNotificationStatisticsAction::class)->execute(
        mailNotificationAdministrator(),
        new MailNotificationReadQuery,
    );
    $suggestions = app(SuggestMailNotificationsAction::class)->execute(
        mailNotificationAdministrator(),
        'receipt',
        limit: 50,
    );

    expect($shown->events)->toHaveCount(1)
        ->and($shown->events[0]->toArray())->not->toHaveKey('metadata')
        ->and($statistics->total)->toBe(2)
        ->and($statistics->successful)->toBe(1)
        ->and($statistics->failed)->toBe(1)
        ->and($suggestions)->toHaveCount(2)
        ->and($abilities)->toBe([
            MailNotificationReadAbility::View,
            MailNotificationReadAbility::Statistics,
            MailNotificationReadAbility::Suggest,
        ]);
});

it('rejects unbounded or non-allowlisted read filters', function (): void {
    expect(fn () => new MailNotificationReadQuery(
        search: str_repeat('x', 161),
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MailNotificationReadQuery(sort: 'metadata'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(SuggestMailNotificationsAction::class)->execute(
            mailNotificationAdministrator(),
            '',
        ))->toThrow(InvalidArgumentException::class);
});
