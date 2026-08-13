<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Nvl\MailNotifications\Actions\GetScheduledMailStatisticsAction;
use Nvl\MailNotifications\Actions\ListScheduledMailMessagesAction;
use Nvl\MailNotifications\Actions\ShowScheduledMailMessageAction;
use Nvl\MailNotifications\Enums\ScheduledMailReadAbility;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Tests\Fixtures\TestTrackable;
use Nvl\MailNotifications\ValueObjects\NotifiableReference;
use Nvl\MailNotifications\ValueObjects\ScheduledMailReadQuery;

function scheduledMailAdministrator(): GenericUser
{
    return new GenericUser([
        'id' => 'scheduled-administrator-1',
        'email' => 'scheduled-administrator@example.test',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-13 12:00:00 UTC');
    config()->set(
        'mail-notifications.management.scheduled_authorization.callback',
        static fn (): bool => true,
    );
    app()->instance(
        MailNotificationNotifiableTypeRegistry::class,
        new MailNotificationNotifiableTypeRegistry(configuredTypes: [
            'test-account' => TestTrackable::class,
        ]),
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('denies scheduled-mail reads until the host supplies an authorization decision', function (): void {
    config()->set(
        'mail-notifications.management.scheduled_authorization.callback',
        null,
    );

    $message = ScheduledMailMessage::factory()->pending()->create();

    expect(fn () => app(ListScheduledMailMessagesAction::class)->execute(
        scheduledMailAdministrator(),
        new ScheduledMailReadQuery,
    ))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ShowScheduledMailMessageAction::class)->execute(
            scheduledMailAdministrator(),
            $message->id,
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => app(GetScheduledMailStatisticsAction::class)->execute(
            scheduledMailAdministrator(),
            new ScheduledMailReadQuery,
        ))->toThrow(AuthorizationException::class);
});

it('returns bounded scheduled-mail pages with one primary recipient and no protected fields', function (): void {
    config()->set(
        'mail-notifications.management.scheduled_maximum_per_page',
        1,
    );
    $matching = ScheduledMailMessage::factory()->due()->create([
        'factory_alias' => 'account.reminder',
        'to_recipients' => [
            ['email' => 'primary@example.test', 'name' => 'Primary'],
            ['email' => 'secondary@example.test', 'name' => 'Secondary'],
        ],
        'cc_recipients' => [
            ['email' => 'copy@example.test', 'name' => null],
        ],
        'bcc_recipients' => [
            ['email' => 'hidden@example.test', 'name' => null],
        ],
        'payload' => ['token' => 'payload-secret'],
        'metadata' => ['private' => 'metadata-secret'],
        'claim_token' => '0198a923-ae6c-71c5-8000-000000000001',
        'notifiable_type' => 'test-account',
        'notifiable_id' => 'account-123',
    ]);
    ScheduledMailMessage::factory()->pending()->create([
        'factory_alias' => 'different.message',
    ]);

    $page = app(ListScheduledMailMessagesAction::class)->execute(
        scheduledMailAdministrator(),
        new ScheduledMailReadQuery(
            factoryAlias: 'account.reminder',
            notifiable: new NotifiableReference('test-account', 'account-123'),
            dueOnly: true,
            perPage: 50,
        ),
    );
    $payload = $page->toArray();

    expect($page->total)->toBe(1)
        ->and($page->perPage)->toBe(1)
        ->and($page->items[0]->id)->toBe($matching->id)
        ->and($payload['data'][0]['primary_recipient'])->toBe([
            'email' => 'primary@example.test',
            'name' => 'Primary',
        ])
        ->and($payload['data'][0])->not->toHaveKeys([
            'payload',
            'to_recipients',
            'cc_recipients',
            'bcc_recipients',
            'metadata',
            'claim_token',
            'locked_until',
            'last_error',
        ]);
});

it('authorizes scheduled list view and statistics independently', function (): void {
    $abilities = [];
    config()->set(
        'mail-notifications.management.scheduled_authorization.callback',
        static function (ScheduledMailReadAbility $ability) use (&$abilities): bool {
            $abilities[] = $ability;

            return true;
        },
    );
    $pending = ScheduledMailMessage::factory()->due()->create([
        'payload' => ['secret' => 'must-not-leak'],
        'metadata' => ['private' => 'must-not-leak'],
    ]);
    ScheduledMailMessage::factory()->sent()->create();

    $page = app(ListScheduledMailMessagesAction::class)->execute(
        scheduledMailAdministrator(),
        new ScheduledMailReadQuery,
    );
    $detail = app(ShowScheduledMailMessageAction::class)->execute(
        scheduledMailAdministrator(),
        $pending->id,
    );
    $statistics = app(GetScheduledMailStatisticsAction::class)->execute(
        scheduledMailAdministrator(),
        new ScheduledMailReadQuery,
    );

    expect($page->total)->toBe(2)
        ->and($detail->id)->toBe($pending->id)
        ->and($detail->toArray())->not->toHaveKeys([
            'payload',
            'to_recipients',
            'cc_recipients',
            'bcc_recipients',
            'metadata',
            'claim_token',
            'locked_until',
            'last_error',
        ])
        ->and($statistics->total)->toBe(2)
        ->and($statistics->statuses[ScheduledMailStatus::Pending->value])->toBe(1)
        ->and($statistics->statuses[ScheduledMailStatus::Sent->value])->toBe(1)
        ->and($statistics->due)->toBe(1)
        ->and($statistics->toArray()['recent'][0])->not->toHaveKeys([
            'payload',
            'metadata',
            'claim_token',
        ])
        ->and($abilities)->toBe([
            ScheduledMailReadAbility::List,
            ScheduledMailReadAbility::View,
            ScheduledMailReadAbility::Statistics,
        ]);
});

it('rejects unbounded scheduled-mail filters and unknown notifiable aliases', function (): void {
    expect(fn () => new ScheduledMailReadQuery(
        factoryAlias: str_repeat('x', 129),
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ScheduledMailReadQuery(sort: 'payload'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ScheduledMailReadQuery(
            status: ScheduledMailStatus::Sent,
            dueOnly: true,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ListScheduledMailMessagesAction::class)->execute(
            scheduledMailAdministrator(),
            new ScheduledMailReadQuery(
                notifiable: new NotifiableReference('unknown', 'account-123'),
            ),
        ))->toThrow(DomainException::class, 'not registered');
});
