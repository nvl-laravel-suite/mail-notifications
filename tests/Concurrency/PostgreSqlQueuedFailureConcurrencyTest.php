<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Events\MailDeliveryStatusChanged;
use Nvl\MailNotifications\Events\MailTrackingFailed;
use Nvl\MailNotifications\Events\MailTrackingStarted;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingContext;

/**
 * Resolve one optional process-control function without a runtime dependency.
 */
function queuedFailureConcurrencyPrimitive(string $function): Closure
{
    if (! function_exists($function)) {
        throw new RuntimeException(sprintf(
            'The queued-failure race requires the [%s] concurrency primitive.',
            $function,
        ));
    }

    return Closure::fromCallable($function);
}

/**
 * Race one terminal callback at the deterministic fallback insert boundary.
 *
 * @param  resource  $socket
 */
function runPostgreSqlQueuedFailureWorker(
    $socket,
    string $queueReference,
): never {
    stream_set_timeout($socket, 25);
    queuedFailureConcurrencyPrimitive('pcntl_alarm')(30);
    $exitCode = 0;

    try {
        DB::purge();
        $connection = DB::connection();
        $connection->statement("SET lock_timeout TO '10s'");
        $waitingAtInsertBoundary = true;
        $observed = [
            'started' => [],
            'failed' => [],
            'changed' => [],
        ];
        $events = app(Dispatcher::class);
        $events->listen(
            MailTrackingStarted::class,
            static function (MailTrackingStarted $event) use (
                &$observed,
            ): void {
                $observed['started'][] = [
                    'id' => $event->attempt->id,
                    'correlation_id' => $event->attempt->correlationId,
                    'category' => $event->category,
                ];
            },
        );
        $events->listen(
            MailTrackingFailed::class,
            static function (MailTrackingFailed $event) use (
                &$observed,
            ): void {
                $observed['failed'][] = [
                    'correlation_id' => $event->correlationId,
                    'attempt_id' => $event->attemptId,
                    'exception' => $event->exceptionClass,
                ];
            },
        );
        $events->listen(
            MailDeliveryStatusChanged::class,
            static function (MailDeliveryStatusChanged $event) use (
                &$observed,
            ): void {
                $observed['changed'][] = [
                    'id' => $event->notificationId,
                    'previous' => $event->previousStatus->value,
                    'current' => $event->currentStatus->value,
                ];
            },
        );
        $table = (new MailNotification)->getTable();
        $connection->beforeExecuting(
            function (string $query) use (
                &$waitingAtInsertBoundary,
                $socket,
                $table,
            ): void {
                $normalizedQuery = Str::of($query)->trim()->lower();

                if (! $waitingAtInsertBoundary
                    || ! $normalizedQuery->startsWith('insert into')
                    || ! $normalizedQuery->contains($table)) {
                    return;
                }

                $waitingAtInsertBoundary = false;

                if (fwrite($socket, "READY\n") === false) {
                    throw new RuntimeException(
                        'A queued-failure worker could not announce readiness.',
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
                        'A queued-failure worker received an invalid start signal.',
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
        $message = new PreparedMessage(
            correlationId: $queueReference,
            mailer: 'array',
            context: TrackingContext::forCategory(
                'test.queued-failure-concurrency',
            ),
            from: new Recipient('sender@example.test', 'Sender'),
            to: [new Recipient('recipient@example.test', 'Recipient')],
            subject: 'Queued failure concurrency',
            queueReference: $queueReference,
        );

        app(DatabaseTrackingLifecycle::class)->queuedFailure(
            $message,
            new RuntimeException('Synthetic terminal queue failure.'),
        );

        if ($waitingAtInsertBoundary) {
            throw new RuntimeException(
                'A queued-failure worker did not reach the insert boundary.',
            );
        }

        $result = [
            'ok' => true,
            'events' => $observed,
        ];
    } catch (Throwable $exception) {
        $exitCode = 1;
        $result = [
            'ok' => false,
            'error' => $exception::class,
            'events' => [],
        ];
    }

    queuedFailureConcurrencyPrimitive('pcntl_alarm')(0);
    fwrite($socket, json_encode($result, JSON_THROW_ON_ERROR));
    fclose($socket);

    exit($exitCode);
}

it('fences concurrent PostgreSQL queued-failure fallbacks and events', function () {
    if ((new MailNotification)->getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'The queued-failure race requires a PostgreSQL connection.',
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
                'The queued-failure race requires the [%s] concurrency primitive.',
                $requiredFunction,
            ));
        }
    }

    if (! defined('STREAM_PF_UNIX')
        || ! defined('STREAM_SOCK_STREAM')) {
        $this->markTestSkipped(
            'The queued-failure race requires Unix socket pairs.',
        );
    }

    $queueReference = (string) Str::uuid();
    $socketPairs = [];
    $parentSockets = [];
    $workerPids = [];
    $workerStatuses = [];
    $workerPayloads = [];
    $workerCount = 2;

    try {
        for ($worker = 0; $worker < $workerCount; $worker++) {
            $pair = stream_socket_pair(
                STREAM_PF_UNIX,
                STREAM_SOCK_STREAM,
                0,
            );

            if ($pair === false) {
                $this->markTestSkipped(
                    'The queued-failure race could not create Unix socket pairs.',
                );
            }

            $socketPairs[$worker] = $pair;
        }

        DB::disconnect();

        for ($worker = 0; $worker < $workerCount; $worker++) {
            $pid = queuedFailureConcurrencyPrimitive('pcntl_fork')();

            if (! is_int($pid)) {
                throw new RuntimeException(
                    'The queued-failure race received an invalid worker process identifier.',
                );
            }

            if ($pid === -1) {
                $this->markTestSkipped(
                    'The queued-failure race could not fork both workers.',
                );
            }

            if ($pid === 0) {
                foreach ($socketPairs as $pairWorker => $pair) {
                    fclose($pair[0]);

                    if ($pairWorker !== $worker) {
                        fclose($pair[1]);
                    }
                }

                runPostgreSqlQueuedFailureWorker(
                    $socketPairs[$worker][1],
                    $queueReference,
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
                    'Queued-failure worker [%d] did not reach the insert boundary.',
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
            $waitedPid = queuedFailureConcurrencyPrimitive(
                'pcntl_waitpid',
            )($workerPid, $status);
            $workerStatuses[$worker] = $status;

            if ($waitedPid === $workerPid) {
                unset($workerPids[$worker]);
            }
        }

        $observed = [
            'started' => [],
            'failed' => [],
            'changed' => [],
        ];

        foreach ($workerPayloads as $worker => $payload) {
            if ($payload === '') {
                throw new RuntimeException(sprintf(
                    'Queued-failure worker [%d] returned no result.',
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
                || ! is_array($result['events'] ?? null)) {
                $error = is_array($result)
                    && is_string($result['error'] ?? null)
                        ? $result['error']
                        : 'unknown';

                throw new RuntimeException(sprintf(
                    'Queued-failure worker [%d] failed [%s].',
                    $worker,
                    $error,
                ));
            }

            $status = $workerStatuses[$worker] ?? null;
            $exited = is_int($status)
                ? queuedFailureConcurrencyPrimitive(
                    'pcntl_wifexited',
                )($status)
                : false;
            $exitCode = is_int($status)
                ? queuedFailureConcurrencyPrimitive(
                    'pcntl_wexitstatus',
                )($status)
                : null;

            if (! is_int($status)
                || $exited !== true
                || $exitCode !== 0) {
                throw new RuntimeException(sprintf(
                    'Queued-failure worker [%d] did not exit cleanly.',
                    $worker,
                ));
            }

            foreach (array_keys($observed) as $eventType) {
                $workerEvents = $result['events'][$eventType] ?? null;

                if (! is_array($workerEvents)) {
                    throw new RuntimeException(sprintf(
                        'Queued-failure worker [%d] returned invalid [%s] events.',
                        $worker,
                        $eventType,
                    ));
                }

                $observed[$eventType] = array_merge(
                    $observed[$eventType],
                    $workerEvents,
                );
            }
        }

        DB::purge();
        $notification = MailNotification::query()
            ->where('queue_reference', $queueReference)
            ->sole();

        expect(MailNotification::query()
            ->where('queue_reference', $queueReference)
            ->count())->toBe(1)
            ->and($notification->id)->toBe($queueReference)
            ->and($notification->correlation_id)->toBe($queueReference)
            ->and($notification->queue_reference)->toBe($queueReference)
            ->and($notification->status)->toBe(MailDeliveryStatus::Failed)
            ->and($notification->failed_at)->not->toBeNull()
            ->and($notification->metadata['failure']['exception'] ?? null)
            ->toBe(RuntimeException::class)
            ->and($observed['started'])->toBe([[
                'id' => $queueReference,
                'correlation_id' => $queueReference,
                'category' => 'test.queued-failure-concurrency',
            ]])
            ->and($observed['failed'])->toBe([[
                'correlation_id' => $queueReference,
                'attempt_id' => $queueReference,
                'exception' => RuntimeException::class,
            ]])
            ->and($observed['changed'])->toBe([[
                'id' => $queueReference,
                'previous' => MailDeliveryStatus::Pending->value,
                'current' => MailDeliveryStatus::Failed->value,
            ]]);
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
            queuedFailureConcurrencyPrimitive(
                'pcntl_waitpid',
            )($workerPid, $status);
        }

        DB::purge();
    }
});
