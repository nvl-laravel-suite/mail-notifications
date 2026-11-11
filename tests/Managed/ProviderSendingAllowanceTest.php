<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Services\Delivery\MessageSender;
use Nvl\MailNotifications\Services\Delivery\ProviderResult;
use Nvl\MailNotifications\Services\Delivery\Providers\MailerSendProvider;
use Nvl\MailNotifications\Services\Delivery\ProviderSendingAllowance;

use function Pest\Laravel\mock;

/** Use a cache store that returns stored integers as numeric strings, as Laravel's Redis store does. */
function useNumericStringCacheStore(): void
{
    Cache::extend('numeric-string', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key): mixed
        {
            $value = parent::get($key);

            return is_int($value) ? (string) $value : $value;
        }
    }));
    config(['cache.stores.numeric-string' => ['driver' => 'numeric-string'], 'cache.default' => 'numeric-string']);
}

function rateLimitedProviderResult(): ProviderResult
{
    return new ProviderResult('failed', null, 'provider_rate_limited', 'The provider rate limit was reached.', true, [], ['status' => 429], 30);
}

it('honours and extends a provider cooldown read back as a numeric string', function (): void {
    $this->freezeTime();
    useNumericStringCacheStore();
    $account = ProviderAccount::factory()->create();
    $allowance = app(ProviderSendingAllowance::class);

    $allowance->pause($account, 30);
    expect($allowance->reserve($account))->toBe(30);

    $allowance->pause($account, 90);
    expect($allowance->reserve($account))->toBe(90);
});

it('still rejects a cooldown value that is not an integer timestamp', function (mixed $value): void {
    $account = ProviderAccount::factory()->create();
    Cache::put('provider-send:'.$account->id.':cooldown', $value, 60);

    expect(fn () => app(ProviderSendingAllowance::class)->reserve($account))->toThrow(UnexpectedValueException::class);
})->with(['soon', '12.5', '-5', 12.5, true]);

it('records a rate-limited attempt when another worker already paused the account', function (): void {
    $this->freezeTime();
    useNumericStringCacheStore();
    $message = EmailMessage::factory()->create();
    $attempt = EmailAttempt::factory()->for($message)->create(['provider_account_id' => $message->provider_account_id, 'status' => 'pending']);
    $message->recipients()->create(['type' => 'to', 'email' => 'reader@example.com', 'delivery_status' => 'pending']);
    mock(MailerSendProvider::class)->shouldReceive('send')->once()->andReturnUsing(function () use ($message): ProviderResult {
        app(ProviderSendingAllowance::class)->pause($message->providerAccount, 45);

        return rateLimitedProviderResult();
    });

    app(MessageSender::class)->send($message->id);

    expect($attempt->fresh()->status)->toBe('failed')
        ->and($message->fresh()->status)->toBe('accepted')
        ->and($message->fresh()->next_attempt_at?->getTimestamp())->toBe(now()->addSeconds(60)->getTimestamp())
        ->and(app(ProviderSendingAllowance::class)->reserve($message->providerAccount))->toBe(45);
});

it('records a rate-limited attempt and reports the failure when the cooldown cannot be stored', function (): void {
    $this->freezeTime();
    Exceptions::fake();
    $message = EmailMessage::factory()->create();
    $attempt = EmailAttempt::factory()->for($message)->create(['provider_account_id' => $message->provider_account_id, 'status' => 'pending']);
    $message->recipients()->create(['type' => 'to', 'email' => 'reader@example.com', 'delivery_status' => 'pending']);
    mock(MailerSendProvider::class)->shouldReceive('send')->once()->andReturn(rateLimitedProviderResult());
    $allowance = mock(ProviderSendingAllowance::class);
    $allowance->shouldReceive('reserve')->once()->andReturn(0);
    $allowance->shouldReceive('pause')->once()->andThrow(new RuntimeException('The cache store is unavailable.'));

    app(MessageSender::class)->send($message->id);

    expect($attempt->fresh()->status)->toBe('failed')
        ->and($message->fresh()->status)->toBe('accepted')
        ->and($message->fresh()->next_attempt_at)->not->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});
