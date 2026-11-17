<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Nvl\MailNotifications\Jobs\SendEmailMessage;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailEvent;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\SenderIdentity;
use Nvl\MailNotifications\Models\WebhookReceipt;
use Nvl\MailNotifications\Services\Delivery\MessageDispatcher;
use Nvl\MailNotifications\Services\Delivery\WebhookProcessor;
use Nvl\MailNotifications\Services\ManagedDelivery;

/**
 * Run a callback under a non-UTC application and PHP default timezone, then restore both.
 */
function underApplicationTimezone(string $timezone, Closure $callback): void
{
    $previous = date_default_timezone_get();
    config()->set('app.timezone', $timezone);
    date_default_timezone_set($timezone);

    try {
        $callback();
    } finally {
        date_default_timezone_set($previous);
    }
}

/** Travel to one absolute instant expressed in the current application timezone. */
function travelToInstant(string $instant): void
{
    test()->travelTo(CarbonImmutable::parse($instant)->setTimezone(date_default_timezone_get()));
}

function utcTimestampClient(): Client
{
    $account = ProviderAccount::factory()->create();
    $sender = SenderIdentity::factory()->for($account)->create(['email' => 'sender@example.com']);

    return Client::factory()->withMailSettings(['provider_account_id' => $account->id, 'sender_identity_id' => $sender->id, 'from_email' => $sender->email])->create(['owner_id' => $account->owner_id]);
}

it('keeps both occurrences of a repeated local hour as distinct UTC send instants', function (): void {
    underApplicationTimezone('Europe/Sofia', function (): void {
        Queue::fake();
        travelToInstant('2027-10-30T12:00:00Z');
        $client = utcTimestampClient();
        $delivery = app(ManagedDelivery::class);
        $allow = static fn (Client $current): bool => true;
        $input = ['to' => [['email' => 'target@example.com']], 'subject' => 'Fallback', 'text' => 'Body'];

        $first = $delivery->send($client, [...$input, 'send_at' => '2027-10-31T03:30:00+03:00'], 'first-occurrence', $allow);
        $second = $delivery->send($client, [...$input, 'send_at' => '2027-10-31T03:30:00+02:00'], 'second-occurrence', $allow);

        expect($first->fresh()->send_at->getTimestamp())->toBe(CarbonImmutable::parse('2027-10-31T00:30:00Z')->getTimestamp())
            ->and($second->fresh()->send_at->getTimestamp())->toBe(CarbonImmutable::parse('2027-10-31T01:30:00Z')->getTimestamp())
            ->and(EmailMessage::query()->whereKey($first->id)->toBase()->value('send_at'))->toBe('2027-10-31 00:30:00')
            ->and(EmailMessage::query()->whereKey($second->id)->toBase()->value('send_at'))->toBe('2027-10-31 01:30:00');

        $dispatcher = app(MessageDispatcher::class);
        travelToInstant('2027-10-31T00:29:59Z');
        expect($dispatcher->dispatchPending())->toBe(0);
        travelToInstant('2027-10-31T00:30:00Z');
        expect($dispatcher->dispatchPending())->toBe(1)
            ->and($first->fresh()->status)->toBe('queued')
            ->and($second->fresh()->status)->toBe('accepted');
        $first->fresh()->update(['status' => 'submitted']);
        travelToInstant('2027-10-31T01:29:59Z');
        expect($dispatcher->dispatchPending())->toBe(0);
        travelToInstant('2027-10-31T01:30:00Z');
        expect($dispatcher->dispatchPending())->toBe(1)
            ->and($second->fresh()->status)->toBe('queued');
        Queue::assertPushed(SendEmailMessage::class, 2);
    });
});

it('quarantines restored intents against the cutoff instant under any application timezone', function (string $timezone): void {
    underApplicationTimezone($timezone, function (): void {
        config()->set('nvl-mail-notifications.managed.delivery.enabled', false);
        travelToInstant('2026-11-20T10:00:00Z');
        $early = EmailMessage::factory()->create();
        travelToInstant('2026-11-20T11:00:00Z');
        $boundary = EmailMessage::factory()->create();
        travelToInstant('2026-11-20T11:00:01Z');
        $late = EmailMessage::factory()->create();
        travelToInstant('2026-11-20T12:00:00Z');

        expect(EmailMessage::query()->whereKey($early->id)->toBase()->value('created_at'))->toBe('2026-11-20 10:00:00');
        $this->artisan('nvl:mail-notifications:delivery:quarantine-restored', ['--before' => '2026-11-20T11:00:00Z', '--dry-run' => true])
            ->expectsOutputToContain('Would hold 2 messages; 2 eligible remain unchanged.')->assertSuccessful();
        $this->artisan('nvl:mail-notifications:delivery:quarantine-restored', ['--before' => '2026-11-20T13:00:00+02:00'])
            ->expectsOutputToContain('Held 2 messages; 0 eligible remain.')->assertSuccessful();

        expect($early->fresh()->status)->toBe('unknown')
            ->and($boundary->fresh()->status)->toBe('unknown')
            ->and($late->fresh()->status)->toBe('accepted');
    });
})->with(['east of UTC' => 'Europe/Sofia', 'west of UTC' => 'America/New_York']);

it('orders provider events by their UTC instants under a non-UTC application timezone', function (): void {
    underApplicationTimezone('Europe/Sofia', function (): void {
        travelToInstant('2026-11-20T12:00:00Z');
        $message = EmailMessage::factory()->create(['status' => 'submitted', 'submitted_at' => now()]);
        $recipient = $message->recipients()->create(['type' => 'to', 'email' => 'reader@example.com', 'delivery_status' => 'submitted']);
        EmailAttempt::factory()->for($message)->create(['provider_account_id' => $message->provider_account_id, 'status' => 'submitted', 'provider_message_id' => 'provider-message-utc']);
        $process = static function (string $id, string $type, string $at) use ($message): void {
            $receipt = WebhookReceipt::factory()->create(['provider_account_id' => $message->provider_account_id, 'payload' => [
                'type' => 'activity.'.$type, 'created_at' => $at,
                'data' => ['id' => $id, 'message_id' => 'provider-message-utc', 'recipient' => 'reader@example.com'],
            ]]);
            app(WebhookProcessor::class)->process($receipt->id);
        };

        $process('event-deferred', 'deferred', '2026-11-20T10:00:00Z');
        $process('event-soft-bounce', 'soft_bounced', '2026-11-20T09:00:00Z');
        $process('event-open-late', 'opened', '2026-11-20T10:30:00Z');
        $process('event-open-early', 'opened', '2026-11-20T11:15:00+02:00');

        $recipient->refresh();
        expect($recipient->delivery_status)->toBe('deferred')
            ->and($recipient->delivery_event_at->getTimestamp())->toBe(CarbonImmutable::parse('2026-11-20T10:00:00Z')->getTimestamp())
            ->and($recipient->opened_at->getTimestamp())->toBe(CarbonImmutable::parse('2026-11-20T09:15:00Z')->getTimestamp())
            ->and(EmailEvent::query()->where('provider_event_id', 'event-deferred')->sole()->occurred_at->getTimestamp())
            ->toBe(CarbonImmutable::parse('2026-11-20T10:00:00Z')->getTimestamp())
            ->and(EmailEvent::query()->toBase()->where('provider_event_id', 'event-deferred')->value('occurred_at'))
            ->toBe('2026-11-20 10:00:00');
    });
});
