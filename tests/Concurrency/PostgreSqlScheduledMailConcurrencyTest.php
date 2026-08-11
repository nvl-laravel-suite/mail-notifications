<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\ScheduledMailClaimer;
use Nvl\MailNotifications\Services\ScheduledMailFinalizer;
use Nvl\MailNotifications\Services\ScheduledMailRecovery;

/**
 * Resolve one optional process-control function without creating an extension
 * dependency for ordinary package consumers.
 */
function scheduledMailConcurrencyPrimitive(string $function): Closure
{
    if (! function_exists($function)) {
        throw new RuntimeException(sprintf(
            'The scheduled-mail claim race requires the [%s] concurrency primitive.',
            $function,
        ));
    }

    return Closure::fromCallable($function);
}

/**
 * Run one isolated scheduled-mail claimant and return its result over a socket.
 *
 * @param  resource  $socket
 */
function runPostgreSqlScheduledClaimWorker($socket, int $limit): never
{
    stream_set_timeout($socket, 25);
    scheduledMailConcurrencyPrimitive('pcntl_alarm')(30);
    $exitCode = 0;

    try {
        DB::purge();
        $connection = DB::connection();
        $connection->statement("SET lock_timeout TO '10s'");
        $waitingAtClaimBoundary = true;
        $connection->beforeExecuting(
            function (string $query) use (
                &$waitingAtClaimBoundary,
                $socket,
            ): void {
                $normalizedQuery = Str::of($query)->trim()->lower();

                if (! $waitingAtClaimBoundary
                    || ! $normalizedQuery->startsWith('select')
                    || ! $normalizedQuery->contains(
                        MailNotificationsTables::ScheduledMessages,
                    )
                    || ! $normalizedQuery->contains('for update')) {
                    return;
                }

                $waitingAtClaimBoundary = false;

                if (fwrite($socket, "READY\n") === false) {
                    throw new RuntimeException(
                        'A scheduled-mail claim worker could not announce readiness.',
                    );
                }

                $command = fgets($socket);
                $startAt = is_string($command)
                    && Str::startsWith(trim($command), 'GO ')
                        ? filter_var(
                            Str::after(trim($command), 'GO '),
                            FILTER_VALIDATE_FLOAT,
                        )
                        : false;

                if (! is_float($startAt)) {
                    throw new RuntimeException(
                        'A scheduled-mail claim worker received an invalid start signal.',
                    );
                }

                $delayMicroseconds = max(
                    0,
                    (int) round(
                        ($startAt - microtime(true)) * 1_000_000,
                    ),
                );

                if ($delayMicroseconds > 0) {
                    usleep($delayMicroseconds);
                }
            },
        );
        $claims = app(ScheduledMailClaimer::class)->claim($limit);

        if ($waitingAtClaimBoundary) {
            throw new RuntimeException(
                'A scheduled-mail claim worker did not reach its locking query.',
            );
        }

        $records = [];

        foreach ($claims as $claim) {
            if (! is_string($claim->claim_token)
                || $claim->claim_token === '') {
                throw new RuntimeException(
                    'A concurrent scheduled-mail claim did not receive a fence token.',
                );
            }

            $records[] = [
                'id' => $claim->id,
                'token' => $claim->claim_token,
            ];
        }

        $result = [
            'ok' => true,
            'claims' => $records,
        ];
    } catch (Throwable $exception) {
        $exitCode = 1;
        $result = [
            'ok' => false,
            'error' => $exception::class,
            'claims' => [],
        ];
    }

    scheduledMailConcurrencyPrimitive('pcntl_alarm')(0);
    fwrite($socket, json_encode($result, JSON_THROW_ON_ERROR));
    fclose($socket);

    exit($exitCode);
}

it('partitions due rows across concurrent PostgreSQL claimers with fenced recovery', function () {
    if ((new ScheduledMailMessage)->getConnection()->getDriverName()
        !== 'pgsql') {
        $this->markTestSkipped(
            'The scheduled-mail claim race requires a PostgreSQL connection.',
        );
    }

    $requiredFunctions = [
        'pcntl_alarm',
        'pcntl_fork',
        'pcntl_waitpid',
        'pcntl_wifexited',
        'pcntl_wexitstatus',
        'stream_socket_pair',
    ];

    foreach ($requiredFunctions as $requiredFunction) {
        if (! function_exists($requiredFunction)) {
            $this->markTestSkipped(sprintf(
                'The scheduled-mail claim race requires the [%s] concurrency primitive.',
                $requiredFunction,
            ));
        }
    }

    if (! defined('STREAM_PF_UNIX')
        || ! defined('STREAM_SOCK_STREAM')) {
        $this->markTestSkipped(
            'The scheduled-mail claim race requires Unix socket pairs.',
        );
    }

    CarbonImmutable::setTestNow('2026-07-30 14:00:00 UTC');
    config()->set([
        'mail-notifications.scheduling.enabled' => true,
        'mail-notifications.scheduling.backoff_seconds' => [0],
        'mail-notifications.scheduling.claim_ttl_seconds' => 60,
    ]);
    $messageIds = [];
    $socketPairs = [];
    $parentSockets = [];
    $workerPids = [];
    $workerStatuses = [];
    $workerPayloads = [];
    $rowCount = 8;
    $workerCount = 2;
    $claimLimit = intdiv($rowCount, $workerCount);

    try {
        for ($index = 0; $index < $rowCount; $index++) {
            $messageId = (string) Str::uuid();
            $messageIds[] = $messageId;
            ScheduledMailMessage::query()->create([
                'id' => $messageId,
                'factory_alias' => 'test.pgsql-concurrency',
                'payload_version' => 1,
                'payload' => ['sequence' => $index],
                'to_recipients' => [[
                    'email' => "concurrency-{$index}@example.test",
                    'name' => null,
                ]],
                'status' => ScheduledMailStatus::Pending,
                'scheduled_for' => CarbonImmutable::now('UTC'),
                'available_at' => CarbonImmutable::now('UTC'),
                'attempts' => 0,
                'max_attempts' => 3,
            ]);
        }

        for ($worker = 0; $worker < $workerCount; $worker++) {
            $pair = stream_socket_pair(
                STREAM_PF_UNIX,
                STREAM_SOCK_STREAM,
                0,
            );

            if ($pair === false) {
                $this->markTestSkipped(
                    'The scheduled-mail claim race could not create Unix socket pairs.',
                );
            }

            $socketPairs[$worker] = $pair;
        }

        DB::disconnect();

        for ($worker = 0; $worker < $workerCount; $worker++) {
            $pid = scheduledMailConcurrencyPrimitive('pcntl_fork')();

            if (! is_int($pid)) {
                throw new RuntimeException(
                    'The scheduled-mail claim race received an invalid worker process identifier.',
                );
            }

            if ($pid === -1) {
                $this->markTestSkipped(
                    'The scheduled-mail claim race could not fork both workers.',
                );
            }

            if ($pid === 0) {
                foreach ($socketPairs as $pairWorker => $pair) {
                    fclose($pair[0]);

                    if ($pairWorker !== $worker) {
                        fclose($pair[1]);
                    }
                }

                runPostgreSqlScheduledClaimWorker(
                    $socketPairs[$worker][1],
                    $claimLimit,
                );
            }

            $workerPids[$worker] = $pid;
        }

        foreach ($socketPairs as $worker => $pair) {
            fclose($pair[1]);
            $parentSockets[$worker] = $pair[0];
            stream_set_timeout($parentSockets[$worker], 35);
        }

        foreach ($parentSockets as $worker => $parentSocket) {
            $ready = fgets($parentSocket);

            if (! is_string($ready) || trim($ready) !== 'READY') {
                throw new RuntimeException(sprintf(
                    'Scheduled-mail claim worker [%d] did not reach the locking boundary.',
                    $worker,
                ));
            }
        }

        $startAt = microtime(true) + 0.2;

        foreach ($parentSockets as $parentSocket) {
            fwrite($parentSocket, sprintf("GO %.6F\n", $startAt));
            stream_socket_shutdown($parentSocket, STREAM_SHUT_WR);
        }

        foreach ($parentSockets as $worker => $parentSocket) {
            $payload = stream_get_contents($parentSocket);
            $workerPayloads[$worker] = is_string($payload)
                ? $payload
                : '';
            fclose($parentSocket);
        }

        foreach ($workerPids as $worker => $workerPid) {
            $status = 0;
            $waitedPid = scheduledMailConcurrencyPrimitive(
                'pcntl_waitpid',
            )($workerPid, $status);
            $workerStatuses[$worker] = $status;

            if ($waitedPid === $workerPid) {
                unset($workerPids[$worker]);
            }
        }

        $workerResults = [];

        foreach ($workerPayloads as $worker => $payload) {
            if ($payload === '') {
                throw new RuntimeException(sprintf(
                    'Scheduled-mail claim worker [%d] returned no result.',
                    $worker,
                ));
            }

            $result = json_decode(
                $payload,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (! is_array($result)
                || ($result['ok'] ?? null) !== true
                || ! is_array($result['claims'] ?? null)) {
                $error = is_array($result)
                    && is_string($result['error'] ?? null)
                        ? $result['error']
                        : 'unknown';

                throw new RuntimeException(sprintf(
                    'Scheduled-mail claim worker [%d] failed [%s].',
                    $worker,
                    $error,
                ));
            }

            $status = $workerStatuses[$worker] ?? null;
            $exited = is_int($status)
                ? scheduledMailConcurrencyPrimitive(
                    'pcntl_wifexited',
                )($status)
                : false;
            $exitCode = is_int($status)
                ? scheduledMailConcurrencyPrimitive(
                    'pcntl_wexitstatus',
                )($status)
                : null;

            if (! is_int($status)
                || $exited !== true
                || $exitCode !== 0) {
                throw new RuntimeException(sprintf(
                    'Scheduled-mail claim worker [%d] did not exit cleanly.',
                    $worker,
                ));
            }

            $workerResults[] = $result['claims'];
        }

        expect($workerResults)->toHaveCount($workerCount);

        $claims = [];

        foreach ($workerResults as $workerClaims) {
            expect($workerClaims)->toHaveCount($claimLimit);

            foreach ($workerClaims as $workerClaim) {
                if (! is_array($workerClaim)
                    || ! is_string($workerClaim['id'] ?? null)
                    || ! is_string($workerClaim['token'] ?? null)) {
                    throw new RuntimeException(
                        'A scheduled-mail claim worker returned an invalid result.',
                    );
                }

                $claims[] = [
                    'id' => $workerClaim['id'],
                    'token' => $workerClaim['token'],
                ];
            }
        }

        $claimedIds = array_column($claims, 'id');
        $claimTokens = array_column($claims, 'token');
        $expectedIds = $messageIds;
        sort($claimedIds);
        sort($expectedIds);

        expect($claimedIds)->toBe($expectedIds)
            ->and(array_unique($claimedIds))->toHaveCount($rowCount)
            ->and(array_unique($claimTokens))->toHaveCount($rowCount);

        DB::purge();
        $tokensByMessage = array_column($claims, 'token', 'id');
        $persistedMessages = ScheduledMailMessage::query()
            ->whereIn('id', $messageIds)
            ->get();

        expect($persistedMessages)->toHaveCount($rowCount);

        foreach ($persistedMessages as $persistedMessage) {
            expect($persistedMessage)
                ->status->toBe(ScheduledMailStatus::Processing)
                ->attempts->toBe(1)
                ->claim_token->toBe(
                    $tokensByMessage[$persistedMessage->id] ?? null,
                );
        }

        $staleClaim = $claims[0];
        ScheduledMailMessage::query()
            ->whereKey($staleClaim['id'])
            ->update([
                'locked_until' => CarbonImmutable::now('UTC')->subSecond(),
            ]);

        expect(app(ScheduledMailRecovery::class)->recover(1))->toBe(1);
        $recovered = ScheduledMailMessage::query()
            ->findOrFail($staleClaim['id']);

        expect($recovered)
            ->status->toBe(ScheduledMailStatus::Pending)
            ->attempts->toBe(1)
            ->claim_token->toBeNull()
            ->last_error->toBe('claim_expired');

        $reclaimed = app(ScheduledMailClaimer::class)->claim(1);

        expect($reclaimed)->toHaveCount(1)
            ->and($reclaimed[0]->id)->toBe($staleClaim['id'])
            ->and($reclaimed[0]->attempts)->toBe(2)
            ->and($reclaimed[0]->claim_token)
            ->toBeString()
            ->not->toBe($staleClaim['token']);

        $replacementToken = $reclaimed[0]->claim_token;

        if (! is_string($replacementToken)) {
            throw new RuntimeException(
                'The recovered scheduled-mail claim did not receive a new token.',
            );
        }

        $finalizer = app(ScheduledMailFinalizer::class);

        expect($finalizer->markSent(
            $staleClaim['id'],
            $staleClaim['token'],
        ))->toBeFalse()
            ->and(ScheduledMailMessage::query()
                ->findOrFail($staleClaim['id'])
                ->claim_token)->toBe($replacementToken)
            ->and($finalizer->markSent(
                $staleClaim['id'],
                $replacementToken,
            ))->toBeTrue();

        $finalized = ScheduledMailMessage::query()
            ->findOrFail($staleClaim['id']);

        expect($finalized)
            ->status->toBe(ScheduledMailStatus::Sent)
            ->attempts->toBe(2)
            ->claim_token->toBeNull();
    } finally {
        foreach ($socketPairs as $pair) {
            foreach ($pair as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
        }

        foreach ($parentSockets as $parentSocket) {
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
        }

        foreach ($workerPids as $workerPid) {
            $status = 0;
            scheduledMailConcurrencyPrimitive(
                'pcntl_waitpid',
            )($workerPid, $status);
        }

        DB::purge();

        if ($messageIds !== []) {
            ScheduledMailMessage::query()
                ->whereIn('id', $messageIds)
                ->delete();
        }

        CarbonImmutable::setTestNow();
    }
});
