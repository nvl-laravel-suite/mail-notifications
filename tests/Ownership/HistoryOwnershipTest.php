<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\MailHistoryAnonymizer;
use Nvl\MailNotifications\Services\MailRetentionPruner;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Nvl\MailNotifications\Tests\OwnerScopeTestCase;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;

uses(OwnerScopeTestCase::class);

function retainedNativeMail(): string
{
    $id = (string) Str::uuid();
    $attempt = app(TrackingLifecycle::class)->begin(new PreparedMessage($id, 'array', TrackingContext::forCategory('test.retention'),
        new Recipient('sender@example.test'), [new Recipient('recipient@example.test')], subject: 'Private subject'));
    app(TrackingLifecycle::class)->accepted($attempt, new ProviderAcceptance(new ProviderMessageId('native-provider', 'retained-'.$id)));
    app(TrackingLifecycle::class)->apply(new VerifiedDeliveryEvent('native-provider', 'retained-event-'.$id, MailDeliveryStatus::Delivered,
        CarbonImmutable::now('UTC')->subDays(3), providerMessageId: 'retained-'.$id, correlationId: $id));
    DB::table((new MailNotification)->getTable())->where('id', $id)->update([
        'status_changed_at' => DatabaseTimestamp::format(CarbonImmutable::now('UTC')->subDays(3)),
    ]);

    return $id;
}

it('anonymizes and prunes only the global mail partition without inventing central authority', function (): void {
    config(['nvl-mail-notifications.retention.anonymization.enabled' => true]);
    $global = retainedNativeMail();
    $owned = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), fn () => retainedNativeMail());
    app(MailHistoryAnonymizer::class)->anonymize(cutoff: CarbonImmutable::now('UTC')->subDay());
    expect(DB::table((new MailNotification)->getTable())->where('id', $global)->value('subject'))->toBeNull()
        ->and(DB::table((new MailNotification)->getTable())->where('id', $owned)->value('subject'))->toBe('Private subject');
    app(MailRetentionPruner::class)->prune(cutoff: CarbonImmutable::now('UTC')->subDay());
    expect(DB::table((new MailNotification)->getTable())->where('id', $global)->exists())->toBeFalse()
        ->and(DB::table((new MailNotification)->getTable())->where('id', $owned)->exists())->toBeTrue();
});
