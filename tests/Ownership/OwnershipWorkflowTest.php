<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Tests\OwnerScopeTestCase;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipException;

uses(OwnerScopeTestCase::class);

function nativeMailPrepared(string $subject): PreparedMessage
{
    return new PreparedMessage((string) Str::uuid(), 'array', TrackingContext::forCategory('test.ownership'),
        new Recipient('sender@example.test'), [new Recipient('recipient@example.test')], subject: $subject);
}

it('keeps tracked mail and transport acceptance in its canonical owner', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $attempt = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function () {
        $attempt = app(TrackingLifecycle::class)->begin(nativeMailPrepared('Owned'));
        app(TrackingLifecycle::class)->accepted($attempt, new ProviderAcceptance(new ProviderMessageId('test-provider', 'owned-message')));
        expect(MailNotification::query()->sole()->provider_message_id)->toBe('owned-message');

        return $attempt;
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, function () use ($attempt): void {
        expect(MailNotification::query()->count())->toBe(0);
        expect(fn () => app(TrackingLifecycle::class)->accepted($attempt, new ProviderAcceptance(new ProviderMessageId('test-provider', 'forged-message'))))->toThrow(ModelNotFoundException::class);
    });
    expect(fn () => MailNotification::query()->get())->toThrow(OwnershipException::class);
});

it('tracks global credential delivery through a fixed mail capability without admitting an owner or central actor', function (): void {
    $attempt = app(TrackingLifecycle::class)->begin(nativeMailPrepared('Global credential'));
    app(TrackingLifecycle::class)->accepted($attempt, new ProviderAcceptance(new ProviderMessageId('test-provider', 'global-message')));
    expect(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved)
        ->and(Company::query()->count())->toBe(2)
        ->and(DB::table((new MailNotification)->getTable())->where('id', $attempt->id)->value('owner_scope_key'))->toBe('central')
        ->and(DB::table((new MailNotification)->getTable())->where('id', $attempt->id)->value('provider_message_id'))->toBe('global-message');
    expect(fn () => MailNotification::query()->get())->toThrow(OwnershipException::class);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () use ($attempt): void {
        expect(fn () => app(TrackingLifecycle::class)->accepted($attempt, new ProviderAcceptance(new ProviderMessageId('test-provider', 'cannot-reclassify'))))->toThrow(ModelNotFoundException::class);
        expect(MailNotification::query()->count())->toBe(0);
    });
});

it('uses the current lifecycle owner when tracking services survive scoped-container cleanup', function (): void {
    $lifecycle = app(TrackingLifecycle::class);
    $this->app->forgetScopedInstances();
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () use ($lifecycle): void {
        $attempt = $lifecycle->begin(nativeMailPrepared('Current lifecycle'));
        expect(MailNotification::query()->whereKey($attempt->id)->exists())->toBeTrue()
            ->and(DB::table((new MailNotification)->getTable())->where('id', $attempt->id)->value('owner_scope_key'))->not->toBe('central');
    });
});
