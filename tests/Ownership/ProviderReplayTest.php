<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Services\ProviderRegistry;
use Nvl\MailNotifications\Services\WebhookProcessor;
use Nvl\MailNotifications\Tests\Fixtures\OwnershipProvider;
use Nvl\MailNotifications\Tests\OwnerScopeTestCase;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\MailNotifications\ValueObjects\WebhookRequest;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnerScopeTestCase::class);

function ownershipMailWebhook(string $messageId): WebhookRequest
{
    $body = json_encode(['event_id' => 'event-'.$messageId, 'message_id' => $messageId, 'occurred_at' => '2026-10-19T00:00:00.000000Z', 'owner_id' => 8], JSON_THROW_ON_ERROR);

    return new WebhookRequest('native-provider', $body, ['content-type' => 'application/json', 'x-signature' => hash_hmac('sha256', $body, 'native-test-secret')], 'POST');
}

function ownershipMailAttempt(string $messageId): void
{
    $attempt = app(TrackingLifecycle::class)->begin(new PreparedMessage((string) Str::uuid(), 'array', TrackingContext::forCategory('test.native'),
        new Recipient('sender@example.test'), [new Recipient('recipient@example.test')]));
    app(TrackingLifecycle::class)->accepted($attempt, new ProviderAcceptance(new ProviderMessageId('native-provider', $messageId)));
}

beforeEach(fn () => app()->instance(ProviderRegistry::class, new ProviderRegistry([new OwnershipProvider])));

it('resolves only stored owner correlation after signature verification and fresh named system admission', function (): void {
    $actor = Actor::query()->findOrFail(1);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, fn () => ownershipMailAttempt('owned-7'));
    $request = ownershipMailWebhook('owned-7');
    expect(fn () => app(WebhookProcessor::class)->process('native-provider', $request))->toThrow(OwnershipViolation::class);
    config(['nvl-ownership.system_operations' => ['mail.provider-callback']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void
        {
            if ($operation !== 'mail.provider-callback' || $owner->getKey() !== 7) {
                throw new OwnershipViolation;
            }
        }
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, function () use ($request): void {
        $result = app(WebhookProcessor::class)->process('native-provider', $request);
        expect($result->applied)->toBeTrue()->and(app(OwnershipContext::class)->requireOwner()->key)->toBe('8')
            ->and(MailNotification::query()->count())->toBe(0);
    });
    $replay = app(WebhookProcessor::class)->process('native-provider', $request);
    expect($replay->duplicate)->toBeTrue();
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function (): void {
        expect(MailNotification::query()->sole()->status)->toBe(MailDeliveryStatus::Delivered)
            ->and(MailNotification::query()->sole()->provider_message_id)->toBe('owned-7')
            ->and(MailNotificationEvent::query()->count())->toBe(1);
    });
});

it('replays global delivery facts without an owner or central actor and rejects bad signatures before lookup', function (): void {
    ownershipMailAttempt('global');
    $request = ownershipMailWebhook('global');
    $result = app(WebhookProcessor::class)->process('native-provider', $request);
    expect($result->applied)->toBeTrue()
        ->and(app(WebhookProcessor::class)->process('native-provider', $request)->duplicate)->toBeTrue()
        ->and(app(OwnershipContext::class)->snapshot()->owner)->toBeNull()
        ->and(DB::table((new MailNotificationEvent)->getTable())->count())->toBe(1);
    config(['nvl-ownership.enabled' => false]);
    expect(fn () => app(WebhookProcessor::class)->process('native-provider', new WebhookRequest('native-provider', $request->body,
        ['content-type' => 'application/json', 'x-signature' => 'bad'], 'POST')))->toThrow(DomainException::class, 'Invalid native provider signature.');
});

it('keeps assigned external provider identities immutable at the database boundary', function (): void {
    ownershipMailAttempt('external-stable');
    $model = new MailNotification;
    foreach (['provider' => 'other-provider', 'provider_message_id' => 'replacement'] as $field => $value) {
        expect(fn () => $model->getConnection()->table($model->getTable())->where('provider_message_id', 'external-stable')->update([$field => $value]))
            ->toThrow(QueryException::class);
    }
    expect($model->getConnection()->table($model->getTable())->where('provider_message_id', 'external-stable')->value('provider'))->toBe('native-provider');
});
