<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Services\MailHistory;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\ProviderRegistry;
use Nvl\MailNotifications\Tests\Fixtures\PluggedProviderAdapter;
use Nvl\MailNotifications\Tests\Fixtures\TestTrackable;
use Nvl\MailNotifications\ValueObjects\NotifiableReference;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;

function nativeMailAdministrator(): GenericUser
{
    return new GenericUser(['id' => 'administrator-1', 'email' => 'administrator@example.test']);
}

it('denies history without a host authorization decision', function (): void {
    app(MailHistory::class)->paginate(nativeMailAdministrator());
})->throws(AuthorizationException::class);

it('returns bounded private-safe history and normalized statistics', function (): void {
    config()->set('nvl-mail-notifications.management.authorization.callback', static fn (): bool => true);
    config()->set('nvl-mail-notifications.management.maximum_per_page', 1);
    $matching = MailNotification::factory()->delivered()->create([
        'subject' => 'Quarterly account report',
        'mailer' => 'mailersend',
        'metadata' => ['secret' => 'must-not-leak'],
        'bcc_recipients' => [['email' => 'hidden@example.test', 'name' => null]],
    ]);
    MailNotification::factory()->failed()->create(['subject' => 'Different delivery']);
    $history = app(MailHistory::class);

    $page = $history->paginate(nativeMailAdministrator(), [
        'search' => 'Quarterly',
        'status' => MailDeliveryStatus::Delivered,
        'perPage' => 50,
    ]);
    $detail = $history->get(nativeMailAdministrator(), $matching->id);
    $statistics = $history->statistics(nativeMailAdministrator());

    expect($page['total'])->toBe(1)
        ->and($page['per_page'])->toBe(1)
        ->and($page['items'][0]['id'])->toBe($matching->id)
        ->and($page['items'][0])->not->toHaveKeys(['metadata', 'bcc_recipients'])
        ->and($detail)->not->toHaveKeys(['metadata', 'bcc_recipients'])
        ->and($statistics['total'])->toBe(2)
        ->and($statistics['statuses'][MailDeliveryStatus::Delivered->value])->toBe(1)
        ->and($statistics['statuses'][MailDeliveryStatus::Failed->value])->toBe(1);
});

it('uses registered exact identities and omits provider metadata', function (): void {
    config()->set('nvl-mail-notifications.management.authorization.callback', static fn (): bool => true);
    app()->instance(MailNotificationNotifiableTypeRegistry::class, new MailNotificationNotifiableTypeRegistry(
        configuredTypes: ['test-account' => TestTrackable::class,
        ],
    ));
    app()->instance(ProviderRegistry::class, new ProviderRegistry([new PluggedProviderAdapter]));
    $matching = MailNotification::factory()->delivered()->create([
        'notifiable_type' => 'test-account',
        'notifiable_id' => 'account-123',
        'provider' => 'plugged-provider',
        'provider_message_id' => 'provider-message-123',
        'subject' => 'Delivery receipt',
    ]);
    MailNotification::factory()->delivered()->create(['notifiable_type' => 'test-account', 'notifiable_id' => 'account-456']);
    MailNotificationEvent::factory()->opened()->create([
        'mail_notification_id' => $matching->id,
        'metadata' => ['raw_webhook' => 'must-not-leak'],
    ]);
    $history = app(MailHistory::class);
    $page = $history->forNotifiable(nativeMailAdministrator(), new NotifiableReference('test-account', 'account-123'));
    $detail = $history->getByProviderMessage(nativeMailAdministrator(), new ProviderMessageId('plugged-provider', 'provider-message-123'));
    $suggestions = $history->suggest(nativeMailAdministrator(), 'Delivery receipt');

    expect($page['total'])->toBe(1)
        ->and($page['items'][0]['id'])->toBe($matching->id)
        ->and($detail['id'])->toBe($matching->id)
        ->and($detail['events'])->toHaveCount(1)
        ->and($detail['events'][0])->not->toHaveKey('metadata')
        ->and($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['id'])->toBe($matching->id);
});

it('keeps data queries bounded as history grows', function (): void {
    config()->set('nvl-mail-notifications.management.authorization.callback', static fn (): bool => true);
    $history = app(MailHistory::class);
    $table = DB::connection()->getQueryGrammar()->wrap((new MailNotification)->getTable());
    $measure = static function () use ($history, $table): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = $history->paginate(nativeMailAdministrator(), ['perPage' => 100]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        expect($page['items'])->toBeArray();

        return count(array_filter($queries, static fn (array $entry): bool => str_contains($entry['query'], $table)));
    };

    MailNotification::factory()->delivered()->create();
    $small = $measure();
    MailNotification::factory()->delivered()->count(24)->create();
    $large = $measure();

    expect($small)->toBe(2)
        ->and($large)->toBe($small);
});

it('returns bounded normalized statistics with grammar-quoted aggregate queries', function (): void {
    config()->set('nvl-mail-notifications.management.authorization.callback', static fn (): bool => true);
    foreach ([['smtp', 'domain.reminder'], ['smtp', 'domain.reminder'], [' ', ' '], ['', '']] as [$mailer, $category]) {
        MailNotification::factory()->create(['mailer' => $mailer, 'message_category' => $category]);
    }
    foreach (range(0, 8) as $index) {
        $key = sprintf('z%02d', $index);
        MailNotification::factory()->create(['mailer' => $key, 'message_category' => $key]);
    }
    $history = app(MailHistory::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $statistics = $history->statistics(nativeMailAdministrator());
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $grammar = DB::connection()->getQueryGrammar();
    $table = $grammar->wrap((new MailNotification)->getTable());
    $group = 'group by '.$grammar->wrap('aggregate_key');
    $dataQueries = array_values(array_filter($queries, static fn (array $entry): bool => str_contains($entry['query'], $table)));
    $aggregates = array_values(array_filter($dataQueries, static fn (array $entry): bool => str_contains($entry['query'], 'aggregate_key')));

    expect($statistics['mailers'])->toHaveCount(10)
        ->and($statistics['categories'])->toHaveCount(10)
        ->and($statistics['mailers'][0])->toBe(['key' => 'smtp', 'count' => 2])
        ->and($statistics['mailers'][1])->toBe(['key' => 'unknown', 'count' => 2])
        ->and($statistics['categories'][0]['key'])->toBe('domain.reminder')
        ->and($dataQueries)->toHaveCount(6)
        ->and($aggregates)->toHaveCount(2)
        ->and($aggregates)->each->toHaveKey('query');
    foreach ($aggregates as $query) {
        expect($query['query'])->toContain($group);
    }
});

it('rejects wrongly typed history filters at the direct service boundary', function (): void {
    config()->set('nvl-mail-notifications.management.authorization.callback', static fn (): bool => true);

    expect(fn () => app(MailHistory::class)->paginate(nativeMailAdministrator(), ['acceptedOnly' => 'yes']))
        ->toThrow(InvalidArgumentException::class);
});
