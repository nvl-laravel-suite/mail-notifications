<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Integrations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Nvl\MailNotifications\Enums\ProviderSyncStatus;
use Nvl\MailNotifications\Jobs\ProcessProviderSyncPage;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\ProviderSyncRun;
use Nvl\MailNotifications\Models\SenderIdentity;
use Nvl\MailNotifications\Models\SendingDomain;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Throwable;
use UnexpectedValueException;

/** Runs durable inventory synchronization after trusted host authorization or queued worker dispatch.
 * @internal
 */
class SyncProviderConfiguration
{
    private const int MAX_PAGE_ATTEMPTS = 5;

    private const int LEASE_SECONDS = 75;

    /** @var list<int> */
    private const array RETRY_DELAYS = [10, 60, 180, 600];

    public function __construct(private ProviderApiManager $manager) {}

    /**
     * Persist an account synchronization intent and queue its first checkpoint; the host must authorize the account.
     */
    public function start(ProviderAccount $account): ProviderSyncRun
    {
        $connection = config()->string('queue.default');
        if (! $this->isDurableConnection($connection)) {
            throw ValidationException::withMessages(['provider_account_id' => 'Provider synchronization requires a durable queue connection such as database or Redis.']);
        }
        $run = DB::connection(ManagedConfiguration::connection())->transaction(function () use ($account, $connection): ProviderSyncRun {
            $account = ProviderAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $active = ProviderSyncRun::where('active_provider_account_id', $account->id)->first();
            if ($active !== null) {
                return $active;
            }

            return ProviderSyncRun::create([
                'provider_account_id' => $account->id,
                'active_provider_account_id' => $account->id,
                'queue_connection' => $connection,
                'configuration_fingerprint' => $this->fingerprint($account),
            ])->refresh();
        }, 3);
        $runId = $run->id;
        $checkpoint = $run->checkpoint;
        DB::connection(ManagedConfiguration::connection())->afterCommit(fn () => $this->dispatchPage($runId, $checkpoint));

        return $run->refresh();
    }

    /**
     * Start the requested account synchronization through the same persisted run boundary.
     */
    public function handle(ProviderAccount $account): ProviderSyncRun
    {
        return $this->start($account);
    }

    /**
     * Resume a retryable persisted run without resetting another worker's current checkpoint.
     */
    public function retry(ProviderSyncRun $run): ProviderSyncRun
    {
        if ($run->refresh()->status !== ProviderSyncStatus::Failed) {
            throw ValidationException::withMessages(['sync' => 'Only a failed synchronization can be restarted.']);
        }

        return $this->start($run->providerAccount()->firstOrFail());
    }

    /**
     * Process one fenced domain/sender checkpoint and persist continuation progress before queueing the next page.
     */
    public function process(string $runId, int $checkpoint): void
    {
        $claim = $this->claim($runId, $checkpoint);
        if ($claim === null) {
            return;
        }
        [$run, $account, $token] = $claim;
        try {
            $adapter = $this->manager->for($account);
            $result = $run->phase === 'domains' ? $adapter->domains($account, $run->cursor) : $adapter->senders($account, $run->cursor);
        } catch (InvalidArgumentException) {
            $result = new ProviderApiResult('invalid_configuration');
        } catch (Throwable) {
            $result = new ProviderApiResult('unavailable');
        }

        if ($result->status === 'ok') {
            try {
                if ($run->phase === 'domains') {
                    $this->domainRows($result->data);
                } else {
                    $this->senderRows($result->data);
                }
            } catch (UnexpectedValueException) {
                $result = new ProviderApiResult('invalid_response');
            }
        }

        $advanced = DB::connection(ManagedConfiguration::connection())->transaction(function () use ($runId, $checkpoint, $account, $token, $result): bool {
            $account = ProviderAccount::whereKey($account->id)->lockForUpdate()->first();
            $run = ProviderSyncRun::whereKey($runId)->lockForUpdate()->first();
            if ($account === null || $run === null || ! $run->status->isActive() || $run->checkpoint !== $checkpoint || $run->lease_token !== $token) {
                return false;
            }
            if (! hash_equals($run->configuration_fingerprint, $this->fingerprint($account))) {
                $this->finishFailed($run, 'configuration_changed');

                return false;
            }
            if (in_array($result->status, ['provider_unavailable', 'unavailable', 'rate_limited'], true)) {
                $this->scheduleRetry($run, $result->status, $result->retryAfter);

                return true;
            }
            if ($result->status === 'unsupported') {
                $this->advancePhase($run, 'unsupported');

                return true;
            }
            if ($result->status !== 'ok') {
                $this->finishFailed($run, in_array($result->status, ['missing_permission', 'permission_denied', 'missing_configuration', 'invalid_configuration', 'invalid_response', 'not_found'], true) ? $result->status : 'invalid_response');

                return false;
            }
            if ($this->paginationStalled($run, $result)) {
                $this->finishFailed($run, 'pagination_stalled');

                return false;
            }
            $phase = $run->phase;
            $newlySeen = $phase === 'domains' ? $this->applyDomains($run, $account, $result) : $this->applySenders($run, $account, $result);
            if ($phase === 'domains') {
                $run->domains_processed += $newlySeen;
            } else {
                $run->senders_processed += $newlySeen;
            }
            $run->pages_processed++;
            if ($result->total !== null) {
                $run->{$phase.'_total'} = $result->total;
            }
            DB::connection(ManagedConfiguration::connection())->table(ManagedConfiguration::table('provider_sync_pages'))->insert([
                'provider_sync_run_id' => $run->id,
                'checkpoint' => $run->checkpoint,
                'phase' => $phase,
                'cursor_hash' => $this->cursorHash($run->cursor),
                'page_hash' => $this->pageHash($result),
            ]);
            if ($result->complete) {
                if ($run->{$phase.'_total'} !== null && $run->{$phase.'_processed'} < $run->{$phase.'_total'}) {
                    $this->finishFailed($run, 'invalid_response');

                    return false;
                }
                $this->markMissing($run, $account);
                $this->advancePhase($run, 'completed');
            } else {
                $run->forceFill([
                    'cursor' => $result->nextCursor,
                    'checkpoint' => $run->checkpoint + 1,
                    'page_attempts' => 0,
                    'status' => ProviderSyncStatus::Queued,
                    $phase.'_status' => 'running',
                    'lease_token' => null,
                    'lease_until' => null,
                    'next_retry_at' => null,
                    'dispatched_at' => null,
                    'error_code' => null,
                    'error_message' => null,
                ])->save();
            }
            $this->synchronizeDomainSenders($account);

            return true;
        }, 3);
        if ($advanced) {
            $run = ProviderSyncRun::find($runId);
            if ($run !== null && $run->status->isActive()) {
                $this->dispatchPage($run->id, $run->checkpoint);
            }
        }
    }

    /** @return array{ProviderSyncRun, ProviderAccount, string}|null */
    private function claim(string $runId, int $checkpoint): ?array
    {
        $accountId = ProviderSyncRun::whereKey($runId)->value('provider_account_id');
        if ($accountId === null) {
            return null;
        }

        return DB::connection(ManagedConfiguration::connection())->transaction(function () use ($runId, $checkpoint, $accountId): ?array {
            $account = ProviderAccount::whereKey($accountId)->lockForUpdate()->first();
            $run = ProviderSyncRun::whereKey($runId)->lockForUpdate()->first();
            if ($account === null || $run === null || ! $run->status->isActive() || $run->checkpoint !== $checkpoint || $run->next_retry_at?->isFuture() || $run->lease_until?->isFuture()) {
                return null;
            }
            if (! hash_equals($run->configuration_fingerprint, $this->fingerprint($account))) {
                $this->finishFailed($run, 'configuration_changed');

                return null;
            }
            if ($run->page_attempts >= self::MAX_PAGE_ATTEMPTS) {
                $this->finishFailed($run, 'retry_exhausted');

                return null;
            }
            $token = (string) Str::uuid();
            $run->forceFill([
                'status' => ProviderSyncStatus::Running,
                $run->phase.'_status' => 'running',
                'page_attempts' => $run->page_attempts + 1,
                'lease_token' => $token,
                'lease_until' => now()->addSeconds(self::LEASE_SECONDS),
                'started_at' => $run->started_at ?? now(),
                'next_retry_at' => null,
                'error_code' => null,
                'error_message' => null,
            ])->save();

            return [$run, $account, $token];
        }, 3);
    }

    /**
     * Requeue due or abandoned synchronization work using bounded persisted checkpoints.
     */
    public function recover(int $limit = 100): int
    {
        $runs = ProviderSyncRun::whereNotNull('active_provider_account_id')
            ->where(fn (Builder $query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinute()))
            ->orderBy('updated_at')->limit(max(1, min(1000, $limit)))->get();
        $dispatched = 0;
        foreach ($runs as $run) {
            if ($this->dispatchPage($run->id, $run->checkpoint)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * Record queue failure only when the run and checkpoint still belong to this worker.
     */
    public function failed(string $runId, int $checkpoint): void
    {
        DB::connection(ManagedConfiguration::connection())->transaction(function () use ($runId, $checkpoint): void {
            $run = ProviderSyncRun::whereKey($runId)->lockForUpdate()->first();
            if ($run === null || $run->checkpoint !== $checkpoint || $run->status !== ProviderSyncStatus::Running || $run->lease_until?->isFuture()) {
                return;
            }
            $this->scheduleRetry($run, 'provider_unavailable');
        }, 3);
    }

    private function dispatchPage(string $runId, int $checkpoint): bool
    {
        $run = DB::connection(ManagedConfiguration::connection())->transaction(function () use ($runId, $checkpoint): ?ProviderSyncRun {
            $run = ProviderSyncRun::whereKey($runId)->lockForUpdate()->first();
            if ($run === null || ! $run->status->isActive() || $run->checkpoint !== $checkpoint || $run->lease_until?->isFuture() || ($run->dispatched_at !== null && $run->dispatched_at->greaterThan(now()->subMinute()))) {
                return null;
            }
            if (config('queue.connections.'.$run->queue_connection.'.driver') === 'sqs' && $run->next_retry_at?->greaterThan(now()->addSeconds(900))) {
                return null;
            }
            $run->forceFill(['dispatched_at' => now(), 'lease_token' => null, 'lease_until' => null])->save();

            return $run;
        }, 3);
        if ($run === null) {
            return false;
        }
        try {
            if (! $this->isDurableConnection($run->queue_connection)) {
                throw new InvalidArgumentException('A durable queue is required.');
            }
            $job = new ProcessProviderSyncPage($run->id, $run->checkpoint);
            $delay = $run->next_retry_at?->isFuture() ? $run->next_retry_at : now();
            $job->onConnection($run->queue_connection)->delay($delay);
            Queue::connection($run->queue_connection)->later($delay, $job);
        } catch (Throwable) {
            $retryAt = $run->next_retry_at?->greaterThan(now()->addMinute()) ? $run->next_retry_at : now()->addMinute();
            ProviderSyncRun::whereKey($run->id)->where('checkpoint', $checkpoint)->where('dispatched_at', $run->dispatched_at)->whereIn('status', ['queued', 'running', 'retrying'])->whereNull('lease_token')->update([
                'error_code' => 'queue_unavailable',
                'error_message' => $this->errorMessage('queue_unavailable'),
                'dispatched_at' => null,
                'next_retry_at' => $retryAt,
            ]);

            return false;
        }

        return true;
    }

    private function isDurableConnection(string $connection): bool
    {
        return in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true);
    }

    private function fingerprint(ProviderAccount $account): string
    {
        $credentials = $account->credentials ?? [];
        ksort($credentials);

        return hash('sha256', json_encode([$account->provider, $account->owner_id, $account->status->value, $credentials], JSON_THROW_ON_ERROR));
    }

    private function paginationStalled(ProviderSyncRun $run, ProviderApiResult $result): bool
    {
        if ((! $result->complete && ($result->nextCursor === null || $result->data === [])) || ($result->complete && $result->nextCursor !== null)) {
            return true;
        }
        if ($result->nextCursor !== null && ($result->nextCursor === $run->cursor || DB::connection(ManagedConfiguration::connection())->table(ManagedConfiguration::table('provider_sync_pages'))->where('provider_sync_run_id', $run->id)->where('phase', $run->phase)->where('cursor_hash', $this->cursorHash($result->nextCursor))->exists())) {
            return true;
        }
        $pageHash = $this->pageHash($result);

        return $pageHash !== null && DB::connection(ManagedConfiguration::connection())->table(ManagedConfiguration::table('provider_sync_pages'))->where('provider_sync_run_id', $run->id)->where('phase', $run->phase)->where('page_hash', $pageHash)->exists();
    }

    private function cursorHash(?string $cursor): string
    {
        return hash('sha256', $cursor ?? 'initial');
    }

    private function pageHash(ProviderApiResult $result): ?string
    {
        if ($result->data === []) {
            return null;
        }
        $ids = array_column($result->data, 'remote_id');
        sort($ids);

        return hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
    }

    private function scheduleRetry(ProviderSyncRun $run, string $code, ?int $retryAfter = null): void
    {
        if ($run->page_attempts >= self::MAX_PAGE_ATTEMPTS) {
            $this->finishFailed($run, 'retry_exhausted');

            return;
        }
        $delay = max(self::RETRY_DELAYS[max(0, $run->page_attempts - 1)], min(86400, max(0, $retryAfter ?? 0)));
        $run->forceFill([
            'status' => ProviderSyncStatus::Retrying,
            $run->phase.'_status' => 'retrying',
            'retry_count' => $run->retry_count + 1,
            'next_retry_at' => now()->addSeconds($delay),
            'lease_token' => null,
            'lease_until' => null,
            'dispatched_at' => null,
            'error_code' => $code,
            'error_message' => $this->errorMessage($code),
        ])->save();
    }

    private function finishFailed(ProviderSyncRun $run, string $code): void
    {
        $run->forceFill([
            'status' => ProviderSyncStatus::Failed,
            $run->phase.'_status' => 'failed',
            'active_provider_account_id' => null,
            'finished_at' => now(),
            'lease_token' => null,
            'lease_until' => null,
            'next_retry_at' => null,
            'dispatched_at' => null,
            'error_code' => $code,
            'error_message' => $this->errorMessage($code),
        ])->save();
    }

    private function advancePhase(ProviderSyncRun $run, string $operationStatus): void
    {
        $completed = $run->phase === 'senders';
        $run->forceFill([
            $run->phase.'_status' => $operationStatus,
            'status' => $completed ? ProviderSyncStatus::Completed : ProviderSyncStatus::Queued,
            'phase' => 'senders',
            'checkpoint' => $run->checkpoint + 1,
            'cursor' => null,
            'page_attempts' => 0,
            'active_provider_account_id' => $completed ? null : $run->provider_account_id,
            'finished_at' => $completed ? now() : null,
            'lease_token' => null,
            'lease_until' => null,
            'next_retry_at' => null,
            'dispatched_at' => null,
            'error_code' => null,
            'error_message' => null,
        ])->save();
    }

    private function errorMessage(string $code): string
    {
        return match ($code) {
            'missing_permission', 'permission_denied' => 'The provider credentials do not have permission to read this resource.',
            'invalid_configuration', 'missing_configuration' => 'Check this provider account configuration and credentials before retrying.',
            'invalid_response' => 'The provider returned an incomplete or invalid response.',
            'not_found' => 'The provider resource is unavailable. Check this account before retrying.',
            'rate_limited' => 'The provider rate limit was reached. This page will retry automatically.',
            'provider_unavailable', 'unavailable' => 'The provider is temporarily unavailable. This page will retry automatically.',
            'retry_exhausted' => 'This page failed after five attempts. Check the provider and retry synchronization.',
            'configuration_changed' => 'The provider account changed during synchronization. Start a new synchronization.',
            'pagination_stalled' => 'Provider pagination stopped advancing. Unseen records have been retained.',
            'queue_unavailable' => 'The queue is unavailable. Synchronization is saved and will be dispatched automatically.',
            default => 'Synchronization could not complete. Check this provider account and retry.',
        };
    }

    private function applyDomains(ProviderSyncRun $run, ProviderAccount $account, ProviderApiResult $result): int
    {
        $count = 0;
        foreach ($this->domainRows($result->data) as $remote) {
            $account->sendingDomains()->where('owner_id', $account->owner_id)->where('remote_id', $remote['remote_id'])->where('domain', '!=', $remote['domain'])->update(['status' => 'missing', 'verified_at' => null, 'last_synced_at' => now()]);
            $domain = $account->sendingDomains()->where('owner_id', $account->owner_id)->firstOrNew(['domain' => $remote['domain']]);
            if ($domain->last_seen_sync_run_id !== $run->id) {
                $count++;
            }
            $domain->forceFill([
                'owner_id' => $account->owner_id, 'provider_account_id' => $account->id,
                'remote_id' => $remote['remote_id'], 'status' => $remote['status'], 'verification_source' => 'provider',
                'dns_records' => $remote['dns_records'], 'verified_at' => $remote['status'] === 'verified' ? now() : null,
                'last_synced_at' => now(), 'last_seen_sync_run_id' => $run->id,
            ])->save();
        }

        return $count;
    }

    private function applySenders(ProviderSyncRun $run, ProviderAccount $account, ProviderApiResult $result): int
    {
        $count = 0;
        foreach ($this->senderRows($result->data) as $remote) {
            $account->senderIdentities()->where('owner_id', $account->owner_id)->where('remote_id', $remote['remote_id'])->where('email', '!=', $remote['email'])->update(['status' => 'missing', 'verified_at' => null, 'last_synced_at' => now()]);
            $domain = isset($remote['domain_remote_id']) ? $account->sendingDomains()->where('owner_id', $account->owner_id)->where('remote_id', $remote['domain_remote_id'])->where('status', '!=', 'missing')->first() : null;
            $sender = $account->senderIdentities()->where('owner_id', $account->owner_id)->firstOrNew(['email' => $remote['email']]);
            if ($sender->last_seen_sync_run_id !== $run->id) {
                $count++;
            }
            $sender->forceFill([
                'owner_id' => $account->owner_id, 'provider_account_id' => $account->id,
                'remote_id' => $remote['remote_id'], 'name' => $remote['name'], 'status' => $remote['status'], 'verification_source' => 'provider',
                'sending_domain_id' => $domain?->id, 'reply_to_email' => $remote['reply_to_email'], 'reply_to_name' => $remote['reply_to_name'],
                'verified_at' => $remote['status'] === 'verified' ? now() : null, 'last_synced_at' => now(), 'last_seen_sync_run_id' => $run->id,
            ])->save();
        }

        return $count;
    }

    private function markMissing(ProviderSyncRun $run, ProviderAccount $account): void
    {
        if ($run->phase === 'domains') {
            $account->sendingDomains()->where('owner_id', $account->owner_id)->where('verification_source', 'provider')
                ->where(/** @param Builder<SendingDomain> $query */ fn (Builder $query) => $query->whereNull('last_seen_sync_run_id')->orWhere('last_seen_sync_run_id', '!=', $run->id))
                ->update(['status' => 'missing', 'verified_at' => null, 'last_synced_at' => now()]);
        } else {
            $account->senderIdentities()->where('owner_id', $account->owner_id)->where('verification_source', 'provider')
                ->where(/** @param Builder<SenderIdentity> $query */ fn (Builder $query) => $query->whereNull('last_seen_sync_run_id')->orWhere('last_seen_sync_run_id', '!=', $run->id))
                ->update(['status' => 'missing', 'verified_at' => null, 'last_synced_at' => now()]);
        }
    }

    private function synchronizeDomainSenders(ProviderAccount $account): void
    {
        SenderIdentity::where('provider_account_id', $account->id)->where('owner_id', $account->owner_id)->where('status', '!=', 'missing')
            ->where('verification_source', 'domain')
            ->whereHas('sendingDomain', fn (Builder $query) => $query->getQuery()->where('status', '!=', 'verified')->orWhereNull('verified_at'))
            ->update(['verified_at' => null, 'status' => 'unverified']);

        SenderIdentity::where('provider_account_id', $account->id)->where('owner_id', $account->owner_id)->where('status', '!=', 'missing')
            ->where('verification_source', 'domain')
            ->whereHas('sendingDomain', fn (Builder $query) => $query->getQuery()->where('owner_id', $account->owner_id)->where('provider_account_id', $account->id)->where('status', 'verified')->whereNotNull('verified_at'))
            ->where(fn (Builder $query) => $query->where('status', '!=', 'verified')->orWhereNull('verified_at'))
            ->update(['verified_at' => now(), 'status' => 'verified']);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array{remote_id: string, domain: string, status: string, dns_records: array<array-key, mixed>}>
     */
    private function domainRows(array $rows): array
    {
        $domains = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['remote_id'] ?? null) || ! is_string($row['domain'] ?? null) || ! is_string($row['status'] ?? null) || ! is_array($row['dns_records'] ?? null)) {
                throw new UnexpectedValueException('The provider returned an invalid domain record.');
            }
            $domains[] = ['remote_id' => $row['remote_id'], 'domain' => $row['domain'], 'status' => $row['status'], 'dns_records' => $row['dns_records']];
        }

        return $domains;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array{remote_id: string, email: string, status: string, name: ?string, domain_remote_id: ?string, reply_to_email: ?string, reply_to_name: ?string}>
     */
    private function senderRows(array $rows): array
    {
        $senders = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['remote_id'] ?? null) || ! is_string($row['email'] ?? null) || ! is_string($row['status'] ?? null)) {
                throw new UnexpectedValueException('The provider returned an invalid sender record.');
            }
            $name = $row['name'] ?? null;
            $domainId = $row['domain_remote_id'] ?? null;
            $replyEmail = $row['reply_to_email'] ?? null;
            $replyName = $row['reply_to_name'] ?? null;
            if (($name !== null && ! is_string($name)) || ($domainId !== null && ! is_string($domainId)) || ($replyEmail !== null && ! is_string($replyEmail)) || ($replyName !== null && ! is_string($replyName))) {
                throw new UnexpectedValueException('The provider returned invalid sender details.');
            }
            $senders[] = ['remote_id' => $row['remote_id'], 'email' => $row['email'], 'status' => $row['status'], 'name' => $name, 'domain_remote_id' => $domainId, 'reply_to_email' => $replyEmail, 'reply_to_name' => $replyName];
        }

        return $senders;
    }
}
