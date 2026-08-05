<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nvl\MailNotifications\Events\MailTrackingStarted;
use Nvl\MailNotifications\Services\MailTrackingEventDispatcher;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;

/**
 * Configure a package storage connection independent from the host default.
 */
function configureMailEventStorageConnection(): Connection
{
    config()->set('database.connections.mail-event-storage', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('mail-event-storage');
    config()->set(
        'mail-notifications.storage.connection',
        'mail-event-storage',
    );

    return DB::connection('mail-event-storage');
}

afterEach(function (): void {
    DB::purge('mail-event-storage');
});

it('dispatches after the storage commit independently from a host transaction', function () {
    $received = [];
    Event::listen(
        MailTrackingStarted::class,
        static function (MailTrackingStarted $event) use (&$received): void {
            $received[] = $event;
        },
    );
    $host = DB::connection();
    $storage = configureMailEventStorageConnection();
    $hostTransactionLevel = $host->transactionLevel();
    $host->beginTransaction();

    try {
        $storage->transaction(function (): void {
            app(MailTrackingEventDispatcher::class)->dispatch(
                new MailTrackingStarted(
                    attempt: new TrackingAttempt(
                        id: 'storage-event-attempt',
                        correlationId: 'storage-event-correlation',
                    ),
                    category: 'storage.event',
                ),
            );
        });

        expect($received)->toHaveCount(1);
    } finally {
        $host->rollBack($hostTransactionLevel);
    }

    expect($received)
        ->toHaveCount(1)
        ->and($received[0]->attempt->id)
        ->toBe('storage-event-attempt');
});

it('does not dispatch when the storage transaction rolls back', function () {
    $received = [];
    Event::listen(
        MailTrackingStarted::class,
        static function (MailTrackingStarted $event) use (&$received): void {
            $received[] = $event;
        },
    );
    $storage = configureMailEventStorageConnection();

    expect(fn () => $storage->transaction(
        function (): never {
            app(MailTrackingEventDispatcher::class)->dispatch(
                new MailTrackingStarted(
                    attempt: new TrackingAttempt(
                        id: 'rolled-back-attempt',
                        correlationId: 'rolled-back-correlation',
                    ),
                    category: 'storage.rollback',
                ),
            );

            throw new RuntimeException('rollback storage transaction');
        },
    ))->toThrow(
        RuntimeException::class,
        'rollback storage transaction',
    )->and($received)->toBe([]);
});
