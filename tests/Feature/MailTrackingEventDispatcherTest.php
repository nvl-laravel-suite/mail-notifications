<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Events\MailTrackingStarted;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\MailTrackingEventDispatcher;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\MailNotifications\ValueObjects\TrackingContext;

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

it('dispatches only approved correlation without reloading persisted metadata', function (): void {
    $received = [];
    Event::listen(
        MailTrackingStarted::class,
        static function (MailTrackingStarted $event) use (&$received): void {
            $received[] = $event;
        },
    );
    $context = TrackingContext::forCategory('domain.reminder')
        ->withMetadata([
            'nested' => ['private' => true],
            'object' => (object) ['private' => true],
            'recipient_email' => 'recipient@example.test',
            'api_token' => 'must-not-leak',
        ])
        ->withCorrelation([
            'reminder_occurrence_id' => 'occurrence-42',
            'attempt' => 2,
        ]);

    $attempt = app(TrackingLifecycle::class)->begin(new PreparedMessage(
        correlationId: '5027f267-245e-4f88-bda2-759d192b4afb',
        mailer: 'array',
        context: $context,
        from: new Recipient('sender@example.test'),
        to: [new Recipient('recipient@example.test')],
    ));
    $notification = MailNotification::query()->findOrFail($attempt->id);

    expect($received)->toHaveCount(1)
        ->and($received[0]->correlation)->toBe([
            'reminder_occurrence_id' => 'occurrence-42',
            'attempt' => 2,
        ])
        ->and(get_object_vars($received[0]))->not->toHaveKeys([
            'metadata',
            'nested',
            'object',
            'recipient_email',
            'api_token',
        ])
        ->and($notification->metadata['correlation'] ?? null)->toEqual($received[0]->correlation);
});
