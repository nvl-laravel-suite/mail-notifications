<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Tests\NotifiableOwnerScopeTestCase;
use Nvl\MailNotifications\ValueObjects\NotifiableReference;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Exceptions\OwnershipException;

uses(NotifiableOwnerScopeTestCase::class);

function notifiableOwnershipMessage(?NotifiableReference $notifiable = null): PreparedMessage
{
    return new PreparedMessage((string) Str::uuid(), 'array', new TrackingContext(category: 'test.references', notifiable: $notifiable),
        new Recipient('sender@example.test'), [new Recipient('recipient@example.test')]);
}

it('rejects an unguarded notifiable alias over foreign adopted mail storage before recording a reference', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $foreign = app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, fn () => app(TrackingLifecycle::class)->begin(notifiableOwnershipMessage()));
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function () use ($foreign): void {
        expect(fn () => app(TrackingLifecycle::class)->begin(notifiableOwnershipMessage(new NotifiableReference('shadow', $foreign->id))))->toThrow(OwnershipException::class);
        expect(MailNotification::query()->count())->toBe(0);
    });
});

it('keeps live global notifiables global and refuses a deleted principal reference', function (): void {
    $attempt = app(TrackingLifecycle::class)->begin(notifiableOwnershipMessage(new NotifiableReference('global.identity', '1')));
    expect(DB::table((new MailNotification)->getTable())->where('id', $attempt->id)->value('owner_scope_key'))->toBe('central');
    DB::table('ownership_test_actors')->where('id', 1)->delete();
    expect(fn () => app(TrackingLifecycle::class)->begin(notifiableOwnershipMessage(new NotifiableReference('global.identity', '1'))))->toThrow(OwnershipException::class);
    expect(DB::table((new MailNotification)->getTable())->count())->toBe(1);
});
