<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\ScheduledMailReader;

function nativeScheduledAdministrator(): GenericUser
{
    return new GenericUser(['id' => 'administrator-1', 'email' => 'administrator@example.test']);
}

it('denies scheduled reads without a host authorization decision', function (): void {
    app(ScheduledMailReader::class)->paginate(nativeScheduledAdministrator());
})->throws(AuthorizationException::class);

it('returns privacy-bounded scheduled details and statistics', function (): void {
    config()->set('nvl-mail-notifications.management.scheduled_authorization.callback', static fn (): bool => true);
    $message = ScheduledMailMessage::factory()->due()->create([
        'factory_alias' => 'account.reminder',
        'payload' => ['token' => 'secret'],
        'metadata' => ['private' => 'secret'],
        'bcc_recipients' => [['email' => 'hidden@example.test', 'name' => null]],
    ]);
    $reader = app(ScheduledMailReader::class);

    $page = $reader->paginate(nativeScheduledAdministrator(), ['factoryAlias' => 'account.reminder']);
    $detail = $reader->get(nativeScheduledAdministrator(), $message->id);
    $statistics = $reader->statistics(nativeScheduledAdministrator());

    expect($page['total'])->toBe(1)
        ->and($page['items'][0]['id'])->toBe($message->id)
        ->and($page['items'][0])->not->toHaveKeys(['payload', 'metadata', 'bcc_recipients', 'claim_token'])
        ->and($detail)->not->toHaveKeys(['payload', 'metadata', 'bcc_recipients', 'claim_token'])
        ->and($statistics['total'])->toBe(1);
});

it('keeps scheduled page data queries constant as rows grow', function (): void {
    config()->set('nvl-mail-notifications.management.scheduled_authorization.callback', static fn (): bool => true);
    $reader = app(ScheduledMailReader::class);
    $table = DB::connection()->getQueryGrammar()->wrap((new ScheduledMailMessage)->getTable());
    $measure = static function () use ($reader, $table): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = $reader->paginate(nativeScheduledAdministrator(), ['perPage' => 100]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        expect($page['items'])->toBeArray();

        return count(array_filter($queries, static fn (array $entry): bool => str_contains($entry['query'], $table)));
    };

    ScheduledMailMessage::factory()->pending()->create();
    $small = $measure();
    ScheduledMailMessage::factory()->pending()->count(24)->create();
    $large = $measure();

    expect($small)->toBe(2)
        ->and($large)->toBe($small);
});
