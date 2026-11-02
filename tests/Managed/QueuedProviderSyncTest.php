<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Nvl\MailNotifications\Contracts\ProviderApiAdapter;
use Nvl\MailNotifications\Enums\ProviderSyncStatus;
use Nvl\MailNotifications\Jobs\ProcessProviderSyncPage;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\ProviderSyncRun;
use Nvl\MailNotifications\Models\SenderIdentity;
use Nvl\MailNotifications\Models\SendingDomain;
use Nvl\MailNotifications\Services\Integrations\ProviderApiManager;
use Nvl\MailNotifications\Services\Integrations\ProviderApiResult;
use Nvl\MailNotifications\Services\Integrations\SyncProviderConfiguration;
use Nvl\MailNotifications\Support\ManagedConfiguration;

beforeEach(function () {
    config()->set('queue.default', 'database');
    Queue::fake();
    Http::preventStrayRequests();
});

function queuedSyncAdapter(): MockInterface
{
    $adapter = Mockery::mock(ProviderApiAdapter::class);
    app()->instance(ProviderApiManager::class, Mockery::mock(ProviderApiManager::class, function (MockInterface $mock) use ($adapter): void {
        $mock->shouldReceive('for')->andReturn($adapter);
    }));

    return $adapter;
}

function syncDomainRow(int $number): array
{
    return ['remote_id' => 'domain-'.$number, 'domain' => 'domain-'.$number.'.example.com', 'status' => 'verified', 'dns_records' => []];
}

function syncSenderRow(int $number): array
{
    return ['remote_id' => 'sender-'.$number, 'email' => 'sender-'.$number.'@example.com', 'name' => 'Sender '.$number, 'status' => 'verified', 'domain_remote_id' => null, 'reply_to_email' => null, 'reply_to_name' => null];
}

function processSyncCheckpoint(ProviderSyncRun $run): void
{
    $run->refresh();
    app(SyncProviderConfiguration::class)->process($run->id, $run->checkpoint);
    $run->refresh();
}

test('domain re verification restores inherited senders while preserving independent verification', function () {
    $account = ProviderAccount::factory()->create();
    $domain = SendingDomain::factory()->for($account)->create(['domain' => 'domain-1.example.com', 'remote_id' => 'domain-1', 'verification_source' => 'provider']);
    $inherited = SenderIdentity::factory()->for($account)->create(['email' => 'inherited@domain-1.example.com', 'sending_domain_id' => $domain->id, 'verification_source' => 'domain', 'status' => 'verified']);
    $manual = SenderIdentity::factory()->for($account)->create(['email' => 'manual@domain-1.example.com', 'sending_domain_id' => $domain->id, 'verification_source' => 'manual', 'status' => 'verified']);
    $provider = SenderIdentity::factory()->for($account)->create(['email' => 'provider@domain-1.example.com', 'sending_domain_id' => $domain->id, 'verification_source' => 'provider', 'status' => 'verified']);
    $verifiedAt = $manual->verified_at->toIso8601String();
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->twice()->andReturn(new ProviderApiResult('ok', [[...syncDomainRow(1), 'status' => 'unverified']], true), new ProviderApiResult('ok', [syncDomainRow(1)], true));
    $adapter->shouldReceive('senders')->once()->andReturn(new ProviderApiResult('unsupported'));
    $service = app(SyncProviderConfiguration::class);
    $run = $service->start($account);

    processSyncCheckpoint($run);

    expect($inherited->fresh())->status->toBe('unverified')->verified_at->toBeNull();
    expect($manual->fresh()->verified_at?->toIso8601String())->toBe($verifiedAt);
    expect($provider->fresh()->verified_at)->not->toBeNull();
    expect($manual->fresh()->isVerified())->toBeFalse();
    processSyncCheckpoint($run);
    $run = $service->start($account);
    processSyncCheckpoint($run);

    expect($inherited->fresh())->status->toBe('verified')->verified_at->not->toBeNull();
    expect($inherited->fresh()->isVerified())->toBeTrue();
    expect($manual->fresh()->isVerified())->toBeTrue();
    expect($provider->fresh()->isVerified())->toBeTrue();
});

test('starting synchronization persists one active queued run without contacting the provider', function () {
    $account = ProviderAccount::factory()->create();
    $service = app(SyncProviderConfiguration::class);

    $run = $service->start($account);
    $duplicate = $service->start($account);

    expect($duplicate->id)->toBe($run->id);
    expect($run->status)->toBe(ProviderSyncStatus::Queued);
    expect($run->domains_total)->toBeNull();
    expect(ProviderSyncRun::count())->toBe(1);
    Queue::assertPushed(ProcessProviderSyncPage::class, 1);
    Http::assertNothingSent();
});

test('sync configuration refuses inline and nondurable queues', function (string $connection) {
    config()->set('queue.default', $connection);
    $account = ProviderAccount::factory()->create();

    expect(fn () => app(SyncProviderConfiguration::class)->start($account))->toThrow(ValidationException::class);

    expect(ProviderSyncRun::count())->toBe(0);
    Http::assertNothingSent();
})->with(['sync', 'deferred', 'background']);

test('queued traversal processes all pages beyond one hundred with truthful unique totals', function () {
    $account = ProviderAccount::factory()->create();
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', array_map(syncDomainRow(...), range(1, 100)), false, nextCursor: 'next-domains', total: 101));
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [syncDomainRow(101)], true, total: 101));
    $adapter->shouldReceive('senders')->once()->andReturn(new ProviderApiResult('ok', array_map(syncSenderRow(...), range(1, 100)), false, nextCursor: 'next-senders'));
    $adapter->shouldReceive('senders')->once()->andReturn(new ProviderApiResult('ok', [syncSenderRow(101)], true));
    $run = app(SyncProviderConfiguration::class)->start($account);

    for ($index = 0; $index < 4; $index++) {
        processSyncCheckpoint($run);
    }

    expect($run->status)->toBe(ProviderSyncStatus::Completed);
    expect($run->domains_processed)->toBe(101)->and($run->senders_processed)->toBe(101)->and($run->pages_processed)->toBe(4);
    expect($run->domains_total)->toBe(101)->and($run->senders_total)->toBeNull();
    expect($account->sendingDomains()->count())->toBe(101)->and($account->senderIdentities()->count())->toBe(101);
});

test('a fully occupied final page waits for explicit traversal completion before missing cleanup', function () {
    $account = ProviderAccount::factory()->create();
    $missing = SendingDomain::factory()->for($account)->create(['verification_source' => 'provider']);
    $dependent = SenderIdentity::factory()->for($account)->create(['sending_domain_id' => $missing->id, 'verification_source' => 'domain', 'email' => 'sender@'.$missing->domain]);
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', array_map(syncDomainRow(...), range(1, 100)), false, nextCursor: 'empty-last'));
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [], true));
    $adapter->shouldReceive('senders')->once()->andReturn(new ProviderApiResult('ok', [], true));
    $run = app(SyncProviderConfiguration::class)->start($account);

    processSyncCheckpoint($run);
    expect($missing->fresh()->isVerified())->toBeTrue()->and($dependent->fresh()->isVerified())->toBeTrue();
    processSyncCheckpoint($run);
    expect($missing->fresh()->status)->toBe('missing')->and($dependent->fresh()->isVerified())->toBeFalse();
    processSyncCheckpoint($run);
    expect($run->status)->toBe(ProviderSyncStatus::Completed)->and($run->domains_processed)->toBe(100);
});

test('failure on a later page preserves unseen provider records', function () {
    $account = ProviderAccount::factory()->create();
    $missing = SendingDomain::factory()->for($account)->create(['verification_source' => 'provider']);
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [syncDomainRow(1)], false, nextCursor: 'second'));
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('missing_permission', message: 'SECRET https://provider.invalid/token'));
    $run = app(SyncProviderConfiguration::class)->start($account);

    processSyncCheckpoint($run);
    processSyncCheckpoint($run);

    expect($run->status)->toBe(ProviderSyncStatus::Failed)->and($run->error_code)->toBe('missing_permission');
    expect($run->error_message)->not->toContain('SECRET')->not->toContain('https://');
    expect($missing->fresh()->isVerified())->toBeTrue();
    expect($run->domains_processed)->toBe(1)->and($run->retry_count)->toBe(0);
});

test('transient failures retry the same page without duplicating progress or resetting attempts', function () {
    $this->freezeTime();
    $account = ProviderAccount::factory()->create();
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('provider_unavailable'));
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [syncDomainRow(1)], true));
    $run = app(SyncProviderConfiguration::class)->start($account);

    processSyncCheckpoint($run);
    expect($run->status)->toBe(ProviderSyncStatus::Retrying)->and($run->checkpoint)->toBe(0)->and($run->pages_processed)->toBe(0)->and($run->page_attempts)->toBe(1);
    expect($run->next_retry_at->timestamp)->toBe(now()->addSeconds(10)->timestamp);
    $this->travel(10)->seconds();
    processSyncCheckpoint($run);
    expect($run->domains_processed)->toBe(1)->and($run->pages_processed)->toBe(1)->and($run->page_attempts)->toBe(0)->and($run->retry_count)->toBe(1);
});

test('rate limit retry honors the provider retry after delay', function () {
    $this->freezeTime();
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('rate_limited', retryAfter: 300));
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());

    processSyncCheckpoint($run);

    expect($run->next_retry_at->timestamp)->toBe(now()->addSeconds(300)->timestamp);
    Queue::assertPushed(ProcessProviderSyncPage::class, fn (ProcessProviderSyncPage $job): bool => $job->delay !== null && $job->delay->getTimestamp() === now()->addSeconds(300)->timestamp);
});

test('permanent configuration and permission errors fail visibly without retries', function (string $error) {
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult($error, message: 'unsafe-token'));
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());

    processSyncCheckpoint($run);

    expect($run->status)->toBe(ProviderSyncStatus::Failed)->and($run->error_code)->toBe($error)->and($run->next_retry_at)->toBeNull();
    expect($run->error_message)->not->toContain('unsafe-token');
})->with(['invalid_configuration', 'missing_permission', 'invalid_response']);

test('durable page attempts stop retries after exhaustion even across recovery', function () {
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->times(5)->andReturn(new ProviderApiResult('provider_unavailable'));
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());

    for ($index = 0; $index < 5; $index++) {
        processSyncCheckpoint($run);
        $this->travel(601)->seconds();
        app(SyncProviderConfiguration::class)->recover();
    }

    expect($run->fresh()->status)->toBe(ProviderSyncStatus::Failed)->and($run->fresh()->page_attempts)->toBe(5)->and($run->fresh()->error_code)->toBe('retry_exhausted');
});

test('stale checkpoints duplicate deliveries and old failure callbacks cannot advance later pages', function () {
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [syncDomainRow(1)], true));
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());
    $service = app(SyncProviderConfiguration::class);

    $service->process($run->id, 0);
    $service->process($run->id, 0);
    (new ProcessProviderSyncPage($run->id, 0))->failed(new RuntimeException('secret'));

    expect($run->fresh()->checkpoint)->toBe(1)->and($run->fresh()->pages_processed)->toBe(1)->and($run->fresh()->status)->toBe(ProviderSyncStatus::Queued);
});

test('expired leases recover without allowing an older page response to commit', function () {
    $account = ProviderAccount::factory()->create();
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturnUsing(function () use ($account): ProviderApiResult {
        expect(DB::transactionLevel())->toBe(0);
        $run = ProviderSyncRun::where('provider_account_id', $account->id)->firstOrFail();
        $run->forceFill(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinute()])->save();

        return new ProviderApiResult('ok', [syncDomainRow(1)], true);
    });
    $run = app(SyncProviderConfiguration::class)->start($account);

    processSyncCheckpoint($run);

    expect($run->pages_processed)->toBe(0)->and($account->sendingDomains()->count())->toBe(0);
    $run->forceFill(['lease_until' => now()->subSecond(), 'dispatched_at' => null])->save();
    expect(app(SyncProviderConfiguration::class)->recover())->toBe(1);
});

test('a changed provider configuration aborts before a page or missing cleanup can commit', function () {
    $account = ProviderAccount::factory()->create();
    $missing = SendingDomain::factory()->for($account)->create(['verification_source' => 'provider']);
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturnUsing(function () use ($account): ProviderApiResult {
        $account->update(['credentials' => ['api_key' => 'replacement-key']]);

        return new ProviderApiResult('ok', [], true);
    });
    $run = app(SyncProviderConfiguration::class)->start($account);

    processSyncCheckpoint($run);

    expect($run->status)->toBe(ProviderSyncStatus::Failed)->and($run->error_code)->toBe('configuration_changed');
    expect($missing->fresh()->isVerified())->toBeTrue();
});

test('unsupported resources are explicit successful skips without missing cleanup', function () {
    $account = ProviderAccount::factory()->create(['provider' => 'mailgun']);
    $sender = SenderIdentity::factory()->for($account)->create(['verification_source' => 'provider', 'status' => 'verified']);
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [], true));
    $adapter->shouldReceive('senders')->once()->andReturn(new ProviderApiResult('unsupported'));
    $run = app(SyncProviderConfiguration::class)->start($account);

    processSyncCheckpoint($run);
    processSyncCheckpoint($run);

    expect($run->status)->toBe(ProviderSyncStatus::Completed)->and($run->domains_status)->toBe('completed')->and($run->senders_status)->toBe('unsupported');
    expect($sender->fresh()->isVerified())->toBeTrue();
});

test('repeated provider cursors or repeated pages terminate safely without missing cleanup', function (bool $repeatCursor) {
    $account = ProviderAccount::factory()->create();
    $missing = SendingDomain::factory()->for($account)->create(['verification_source' => 'provider']);
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [syncDomainRow(1)], false, nextCursor: 'next'));
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', $repeatCursor ? [syncDomainRow(2)] : [syncDomainRow(1)], false, nextCursor: $repeatCursor ? 'next' : 'third'));
    $run = app(SyncProviderConfiguration::class)->start($account);

    processSyncCheckpoint($run);
    processSyncCheckpoint($run);

    expect($run->status)->toBe(ProviderSyncStatus::Failed)->and($run->error_code)->toBe('pagination_stalled');
    expect($missing->fresh()->isVerified())->toBeTrue();
})->with([true, false]);

test('queue dispatch failure retains a recoverable synchronization intent', function () {
    Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('redis://secret@internal'));
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());

    expect($run->status)->toBe(ProviderSyncStatus::Queued)->and($run->error_code)->toBe('queue_unavailable');
    expect($run->error_message)->not->toContain('secret')->not->toContain('redis://');
    Queue::swap(new QueueFake(app()));
    $this->travel(61)->seconds();
    expect(app(SyncProviderConfiguration::class)->recover())->toBe(1);
    Queue::assertPushed(ProcessProviderSyncPage::class);
});

test('explicit retry starts a fresh run while preserving previous failed progress', function () {
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('missing_permission'));
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());
    processSyncCheckpoint($run);

    $replacement = app(SyncProviderConfiguration::class)->retry($run);

    expect($replacement->id)->not->toBe($run->id)->and($replacement->status)->toBe(ProviderSyncStatus::Queued);
    expect($run->fresh()->status)->toBe(ProviderSyncStatus::Failed);
});

test('HTTP provider failures map to durable transient retries and permanent operator errors', function (int $responseStatus, string $expectedStatus, string $errorCode) {
    Http::fake(['api.mailersend.com/v1/domains*' => Http::response([], $responseStatus)]);
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());

    processSyncCheckpoint($run);

    expect($run->status->value)->toBe($expectedStatus);
    expect($run->error_code)->toBe($errorCode);
    Http::assertSentCount(1);
})->with([[503, 'retrying', 'unavailable'], [401, 'failed', 'permission_denied'], [404, 'failed', 'not_found']]);

test('empty provider credentials fail without a provider call', function () {
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create(['credentials' => []]));

    processSyncCheckpoint($run);

    expect($run->status)->toBe(ProviderSyncStatus::Failed)->and($run->error_code)->toBe('missing_configuration');
    Http::assertNothingSent();
});

test('terminal factory states release active account uniqueness for later runs', function (string $state) {
    $account = ProviderAccount::factory()->create();
    $history = ProviderSyncRun::factory()->for($account)->{$state}()->create();

    $run = app(SyncProviderConfiguration::class)->start($account);

    expect($history->active_provider_account_id)->toBeNull();
    expect($run->id)->not->toBe($history->id);
})->with(['completed', 'failed']);

test('real database queued jobs persist serialized pages and process one phase per delivery', function () {
    Queue::swap(Queue::getFacadeRoot()->queue);
    Http::fake([
        'api.mailersend.com/v1/domains*' => Http::response(['data' => [['id' => 'real-domain', 'name' => 'agency.example.com', 'is_verified' => true]]]),
        'api.mailersend.com/v1/identities*' => Http::response(['data' => [['id' => 'real-sender', 'email' => 'amy@client.example.com', 'name' => 'Amy', 'is_verified' => true, 'domain_id' => 'real-domain']]]),
    ]);
    $account = ProviderAccount::factory()->create();

    $run = app(SyncProviderConfiguration::class)->start($account);

    expect(DB::table('jobs')->count())->toBe(1);
    Http::assertNothingSent();
    $firstPage = Queue::connection('database')->pop();
    expect($firstPage)->not->toBeNull();
    $firstPage->fire();
    expect($run->fresh()->pages_processed)->toBe(1)->and($run->fresh()->phase)->toBe('senders');
    Http::assertSentCount(1);
    expect(DB::table('jobs')->count())->toBe(1);
    $secondPage = Queue::connection('database')->pop();
    expect($secondPage)->not->toBeNull();
    $secondPage->fire();

    expect($run->fresh()->status)->toBe(ProviderSyncStatus::Completed)->and($run->fresh()->pages_processed)->toBe(2);
    expect(DB::table('jobs')->count())->toBe(0);
    expect($account->senderIdentities()->firstOrFail()->isVerified())->toBeTrue();
    Http::assertSentCount(2);
});

test('only failed runs can be explicitly retried through the synchronization service', function () {
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());

    expect(fn () => app(SyncProviderConfiguration::class)->retry($run))->toThrow(ValidationException::class);

    expect(ProviderSyncRun::count())->toBe(1);
});

test('queue dispatch failure never shortens a provider retry after delay', function () {
    $this->freezeTime();
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('rate_limited', retryAfter: 3600));
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());
    Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('queue unavailable'));

    processSyncCheckpoint($run);

    expect($run->next_retry_at->timestamp)->toBe(now()->addHour()->timestamp);
    expect($run->error_code)->toBe('queue_unavailable');
    Queue::swap(new QueueFake(app()));
    $this->travel(61)->seconds();
    expect(app(SyncProviderConfiguration::class)->recover())->toBe(0);
});

test('long SQS retry delays remain durable until the scheduler can dispatch them', function () {
    $this->freezeTime();
    config()->set('queue.default', 'sqs');
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('rate_limited', retryAfter: 3600));
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());

    processSyncCheckpoint($run);

    expect($run->next_retry_at->timestamp)->toBe(now()->addHour()->timestamp);
    Queue::assertPushed(ProcessProviderSyncPage::class, 1);
    $this->travel(3600)->seconds();
    expect(app(SyncProviderConfiguration::class)->recover())->toBe(1);
    Queue::assertPushed(ProcessProviderSyncPage::class, 2);
});

test('overlapping pages count each resource once and retain earlier page markers at completion', function () {
    $account = ProviderAccount::factory()->create();
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [syncDomainRow(1)], false, nextCursor: 'overlap'));
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [syncDomainRow(1), syncDomainRow(2)], true, total: 2));
    $run = app(SyncProviderConfiguration::class)->start($account);

    processSyncCheckpoint($run);
    processSyncCheckpoint($run);

    expect($run->domains_processed)->toBe(2)->and($run->pages_processed)->toBe(2);
    expect($account->sendingDomains()->where('status', 'verified')->count())->toBe(2);
});

test('a declared total larger than the final traversal prevents destructive missing cleanup', function () {
    $account = ProviderAccount::factory()->create();
    $missing = SendingDomain::factory()->for($account)->create(['verification_source' => 'provider']);
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [syncDomainRow(1)], true, total: 2));
    $run = app(SyncProviderConfiguration::class)->start($account);

    processSyncCheckpoint($run);

    expect($run->status)->toBe(ProviderSyncStatus::Failed)->and($run->error_code)->toBe('invalid_response');
    expect($missing->fresh()->isVerified())->toBeTrue();
});

test('provider configuration changes before claim abort without making a request', function () {
    $account = ProviderAccount::factory()->create();
    $run = app(SyncProviderConfiguration::class)->start($account);
    $account->update(['credentials' => ['api_key' => 'changed']]);

    processSyncCheckpoint($run);

    expect($run->status)->toBe(ProviderSyncStatus::Failed)->and($run->error_code)->toBe('configuration_changed');
    Http::assertNothingSent();
});

test('an active page lease prevents duplicate provider calls and recovery dispatches', function () {
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());
    $run->forceFill(['status' => ProviderSyncStatus::Running, 'lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinute()])->save();

    processSyncCheckpoint($run);
    expect(app(SyncProviderConfiguration::class)->recover())->toBe(0);

    expect($run->pages_processed)->toBe(0);
    Http::assertNothingSent();
});

test('a crashed final page attempt fails after recovery without resetting the retry budget', function () {
    $run = app(SyncProviderConfiguration::class)->start(ProviderAccount::factory()->create());
    $run->forceFill(['status' => ProviderSyncStatus::Running, 'page_attempts' => 5, 'lease_token' => (string) Str::uuid(), 'lease_until' => now()->subSecond(), 'dispatched_at' => now()->subMinutes(2)])->save();

    expect(app(SyncProviderConfiguration::class)->recover())->toBe(1);
    processSyncCheckpoint($run);

    expect($run->status)->toBe(ProviderSyncStatus::Failed)->and($run->error_code)->toBe('retry_exhausted')->and($run->page_attempts)->toBe(5);
    Http::assertNothingSent();
});

test('renamed domain and sender remote identities are invalidated only within their account', function () {
    $account = ProviderAccount::factory()->create();
    $oldDomain = SendingDomain::factory()->for($account)->create(['remote_id' => 'domain-1', 'verification_source' => 'provider']);
    $oldSender = SenderIdentity::factory()->for($account)->create(['remote_id' => 'sender-1', 'verification_source' => 'provider', 'status' => 'verified', 'sending_domain_id' => $oldDomain->id]);
    $foreignDomain = SendingDomain::factory()->create(['remote_id' => 'domain-1', 'verification_source' => 'provider']);
    $foreignSender = SenderIdentity::factory()->for($foreignDomain->providerAccount)->create(['remote_id' => 'sender-1', 'verification_source' => 'provider', 'status' => 'verified']);
    $adapter = queuedSyncAdapter();
    $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [syncDomainRow(1)], true));
    $adapter->shouldReceive('senders')->once()->andReturn(new ProviderApiResult('ok', [[...syncSenderRow(1), 'domain_remote_id' => 'domain-1']], false, nextCursor: 'next'));
    $run = app(SyncProviderConfiguration::class)->start($account);

    processSyncCheckpoint($run);
    expect($oldDomain->fresh()->status)->toBe('missing')->and($oldSender->fresh()->isVerified())->toBeFalse();
    processSyncCheckpoint($run);

    expect($oldSender->fresh()->status)->toBe('missing');
    expect($account->senderIdentities()->where('email', 'sender-1@example.com')->firstOrFail()->isVerified())->toBeTrue();
    expect($foreignDomain->fresh()->isVerified())->toBeTrue()->and($foreignSender->fresh()->isVerified())->toBeTrue();
});

test('invalid adapter discovery records fail without changing existing inventory', function (string $phase): void {
    $account = ProviderAccount::factory()->create();
    $existing = SendingDomain::factory()->for($account)->create(['domain' => 'retained.example.com', 'verification_source' => 'provider', 'status' => 'verified', 'verified_at' => now()]);
    $adapter = queuedSyncAdapter();
    if ($phase === 'domains') {
        $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('ok', [['remote_id' => ['invalid'], 'domain' => 'broken.example.com', 'status' => 'verified', 'dns_records' => []]], true));
    } else {
        $adapter->shouldReceive('domains')->once()->andReturn(new ProviderApiResult('unsupported'));
        $adapter->shouldReceive('senders')->once()->andReturn(new ProviderApiResult('ok', [['remote_id' => 'sender', 'email' => 'sender@example.com', 'status' => 'verified', 'name' => ['invalid']]], true));
    }
    $run = app(SyncProviderConfiguration::class)->start($account);
    processSyncCheckpoint($run);
    if ($phase === 'senders') {
        processSyncCheckpoint($run);
    }

    expect($run->fresh()->status)->toBe(ProviderSyncStatus::Failed);
    expect($run->fresh()->error_code)->toBe('invalid_response');
    expect($existing->fresh()->status)->toBe('verified');
    expect($account->sendingDomains()->count())->toBe(1);
    expect($account->senderIdentities()->count())->toBe(0);
})->with(['domains', 'senders']);

test('synchronization dispatch follows the actual outer commit and is discarded by rollback', function (): void {
    $account = ProviderAccount::factory()->create();
    $connection = DB::connection(ManagedConfiguration::connection());
    $sync = app(SyncProviderConfiguration::class);
    $connection->beginTransaction();
    $rolledBack = $sync->start($account);
    expect(ProviderSyncRun::query()->whereKey($rolledBack->id)->exists())->toBeTrue();
    Queue::assertNothingPushed();
    $connection->rollBack();
    Queue::assertNothingPushed();
    expect(ProviderSyncRun::query()->whereKey($rolledBack->id)->exists())->toBeFalse();

    $connection->beginTransaction();
    $committed = $sync->start($account);
    Queue::assertNothingPushed();
    $connection->commit();

    Queue::assertPushed(ProcessProviderSyncPage::class, 1);
    Queue::assertPushed(ProcessProviderSyncPage::class, fn (ProcessProviderSyncPage $job): bool => $job->runId === $committed->id);
    expect($committed->fresh()->dispatched_at)->not->toBeNull();
});
