<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\Contracts\TrackableMessage;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Events\ScheduledMailCancelled;
use Nvl\MailNotifications\Events\ScheduledMailClaimed;
use Nvl\MailNotifications\Events\ScheduledMailFailed;
use Nvl\MailNotifications\Events\ScheduledMailRecovered;
use Nvl\MailNotifications\Events\ScheduledMailReplaced;
use Nvl\MailNotifications\Events\ScheduledMailRescheduled;
use Nvl\MailNotifications\Events\ScheduledMailRetrying;
use Nvl\MailNotifications\Events\ScheduledMailScheduled;
use Nvl\MailNotifications\Events\ScheduledMailSent;
use Nvl\MailNotifications\Exceptions\MailDeliveryCancelled;
use Nvl\MailNotifications\Exceptions\ScheduledMailException;
use Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\Services\ScheduledMailClaimer;
use Nvl\MailNotifications\Services\ScheduledMailFinalizer;
use Nvl\MailNotifications\Services\ScheduledMailProcessor;
use Nvl\MailNotifications\Services\ScheduledMailRecovery;
use Nvl\MailNotifications\Services\ScheduledMailScheduler;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Tests\Fixtures\FailingScheduledTestFactory;
use Nvl\MailNotifications\Tests\Fixtures\ScheduledTestFactory;
use Nvl\MailNotifications\Tests\Fixtures\ScheduledTestMail;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email;

/**
 * Build one valid scheduled-mail request.
 */
function scheduledMailRequest(
    string $alias = 'test.scheduled',
    ?CarbonImmutable $scheduledFor = null,
    ?int $maxAttempts = null,
    ?CarbonImmutable $availableAt = null,
    array $payload = ['body' => 'Scheduled body'],
    array $metadata = [
        'token' => 'secret-value',
        'safe' => 'visible-value',
    ],
): ScheduleMailData {
    return new ScheduleMailData(
        factoryAlias: $alias,
        payloadVersion: 1,
        payload: $payload,
        recipients: new ScheduledRecipients(
            to: [
                new Recipient('primary@example.test', 'Primary'),
                new Recipient('PRIMARY@example.test', 'Replacement'),
            ],
            cc: [
                new Recipient('primary@example.test'),
                new Recipient('copy@example.test', 'Copy'),
            ],
            bcc: [
                new Recipient('copy@example.test'),
                new Recipient('hidden@example.test'),
            ],
        ),
        scheduledFor: $scheduledFor ?? CarbonImmutable::now('UTC'),
        metadata: $metadata,
        maxAttempts: $maxAttempts,
        availableAt: $availableAt,
    );
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-30 12:00:00 UTC');
    config()->set('mail-notifications.scheduling.enabled', true);
    config()->set('mail-notifications.scheduling.backoff_seconds', [60, 300]);
    app()->singleton(ScheduledTestFactory::class);
    app()->singleton(FailingScheduledTestFactory::class);
    app()->tag([
        ScheduledTestFactory::class,
        FailingScheduledTestFactory::class,
    ], ScheduledMessageFactory::TAG);
    app()->forgetInstance(ScheduledMessageFactoryRegistry::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('keeps scheduling disabled by default and refuses mutations while disabled', function () {
    config()->set('mail-notifications.scheduling.enabled', false);

    expect(config('mail-notifications.scheduling.enabled'))->toBeFalse()
        ->and(fn () => app(ScheduledMailScheduler::class)->schedule(
            scheduledMailRequest(),
        ))->toThrow(ScheduledMailException::class, 'disabled')
        ->and(ScheduledMailMessage::query()->count())->toBe(0);
});

it('leaves scheduled processing cadence to the host application', function () {
    config()->set('mail-notifications.scheduling.enabled', true);

    $commands = collect(app(Schedule::class)->events())
        ->map(static fn ($event): string => (string) $event->command);

    expect($commands->contains(
        static fn (string $command): bool => str_contains(
            $command,
            'nvl:mail-notifications:process-scheduled',
        ) || str_contains(
            $command,
            'nvl:mail-notifications:recover-scheduled',
        ),
    ))->toBeFalse();
});

it('rejects malformed scheduling feature switches', function (string $key) {
    config()->set($key, 'false');

    expect(fn () => app(ScheduledMailScheduler::class)->schedule(
        scheduledMailRequest(),
    ))->toThrow(ScheduledMailException::class, 'must be a boolean');
})->with([
    'package switch' => 'mail-notifications.enabled',
    'scheduling switch' => 'mail-notifications.scheduling.enabled',
]);

it('rejects list-shaped payload and metadata before schedule or replacement', function () {
    $scheduler = app(ScheduledMailScheduler::class);

    expect(fn () => $scheduler->schedule(
        scheduledMailRequest(payload: ['list payload']),
    ))->toThrow(
        ScheduledMailException::class,
        'payload must use string keys',
    )->and(fn () => $scheduler->schedule(
        scheduledMailRequest(metadata: ['list metadata']),
    ))->toThrow(
        ScheduledMailException::class,
        'metadata must use string keys',
    )->and(ScheduledMailMessage::query()->count())->toBe(0);

    $message = $scheduler->schedule(scheduledMailRequest());
    $originalPayload = $message->payload;
    $originalMetadata = $message->metadata;

    expect(fn () => $scheduler->replacePending(
        $message->id,
        scheduledMailRequest(payload: ['replacement payload']),
    ))->toThrow(
        ScheduledMailException::class,
        'payload must use string keys',
    )->and(fn () => $scheduler->replacePending(
        $message->id,
        scheduledMailRequest(metadata: ['replacement metadata']),
    ))->toThrow(
        ScheduledMailException::class,
        'metadata must use string keys',
    );

    $message = $message->fresh();

    expect($message)
        ->not->toBeNull()
        ->payload->toEqual($originalPayload)
        ->metadata->toEqual($originalMetadata)
        ->and(ScheduledMailMessage::query()->count())->toBe(1);
});

it('skips factory and schema readiness checks while scheduling is disabled', function () {
    config()->set('mail-notifications.scheduling.enabled', false);
    app()->forgetInstance(ScheduledMessageFactoryRegistry::class);

    expect(app()->resolved(ScheduledMessageFactoryRegistry::class))->toBeFalse();

    $checks = collect(app(MailNotificationsDoctor::class)->inspect())
        ->keyBy('key');

    expect($checks['scheduling'])
        ->passed->toBeTrue()
        ->message->toContain('checks were skipped')
        ->and(app()->resolved(ScheduledMessageFactoryRegistry::class))
        ->toBeFalse();
});

it('requires scheduled history schema for pruning while scheduling is disabled', function () {
    config()->set('mail-notifications.scheduling.enabled', false);
    config()->set(
        'mail-notifications.retention.scheduled_messages.enabled',
        true,
    );
    config()->set(
        'mail-notifications.storage.tables.scheduled_messages',
        'missing_scheduled_history',
    );
    app()->forgetInstance(ScheduledMessageFactoryRegistry::class);

    $checks = collect(app(MailNotificationsDoctor::class)->inspect())
        ->keyBy('key');

    expect($checks['scheduling'])
        ->passed->toBeTrue()
        ->message->toContain('pruning or anonymization')
        ->and($checks['scheduling.schema'])
        ->passed->toBeFalse()
        ->message->toContain('missing_scheduled_history')
        ->and(app()->resolved(ScheduledMessageFactoryRegistry::class))
        ->toBeFalse();
});

it('reports scheduling bounds, factory count, and full schema readiness', function () {
    $checks = collect(app(MailNotificationsDoctor::class)->inspect())
        ->keyBy('key');

    expect($checks['scheduling.configuration'])
        ->passed->toBeTrue()
        ->message->toContain('2 factory/factories')
        ->and($checks['scheduling.schema'])
        ->passed->toBeTrue()
        ->message->toContain('operational indexes');
});

it('reports invalid recipient bounds as scheduling configuration failures', function () {
    config()->set('mail-notifications.scheduling.max_recipients', 0);
    $checks = collect(app(MailNotificationsDoctor::class)->inspect())
        ->keyBy('key');

    expect($checks['scheduling.configuration'])
        ->passed->toBeFalse()
        ->message->toContain('recipient limit');
});

it('detects wrong scheduled schema definitions and missing indexes', function () {
    config()->set(
        'mail-notifications.storage.tables.scheduled_messages',
        'invalid_scheduled_mail_messages',
    );
    Schema::create(
        'invalid_scheduled_mail_messages',
        function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('factory_alias', 10);
            $table->string('payload_version');
            $table->string('payload');
            $table->string('to_recipients');
            $table->string('cc_recipients')->nullable();
            $table->string('bcc_recipients')->nullable();
            $table->string('status', 32)->default('sent');
            $table->string('scheduled_for');
            $table->string('available_at');
            $table->string('attempts')->default('zero');
            $table->string('max_attempts')->default('three');
            $table->string('last_attempt_at')->nullable();
            $table->string('claim_token');
            $table->string('locked_until')->nullable();
            $table->string('last_error');
            $table->string('notifiable_type', 128)->nullable();
            $table->string('notifiable_id', 128)->nullable();
            $table->string('metadata')->nullable();
            $table->string('sent_at')->nullable();
            $table->string('failed_at')->nullable();
            $table->string('cancelled_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        },
    );
    $checks = collect(app(MailNotificationsDoctor::class)->inspect())
        ->keyBy('key');

    expect($checks['scheduling.schema'])
        ->passed->toBeFalse()
        ->message->toContain('incompatible definitions')
        ->message->toContain('missing indexes');
});

it('detects a missing scheduled retention index', function () {
    $table = (new ScheduledMailMessage)->getTable();

    Schema::table($table, function (Blueprint $table): void {
        $table->dropIndex('scheduled_mail_retention_sent_index');
    });

    $checks = collect(app(MailNotificationsDoctor::class)->inspect())
        ->keyBy('key');

    expect($checks['scheduling.schema'])
        ->passed->toBeFalse()
        ->message->toContain('sent retention lookup');
});

it('rejects a recorded scheduled schema missing a retention index', function () {
    $migrationName =
        '2026_07_30_000100_create_scheduled_mail_messages_table';
    $table = (new ScheduledMailMessage)->getTable();

    expect(DB::table('migrations')
        ->where('migration', $migrationName)
        ->exists())->toBeTrue();

    Schema::table($table, function (Blueprint $table): void {
        $table->dropIndex('scheduled_mail_retention_failed_index');
    });

    $migration = require dirname(__DIR__, 2)
        .'/database/migrations/'.$migrationName.'.php';

    expect(static fn () => $migration->up())
        ->toThrow(LogicException::class, 'failed retention lookup');
});

it('refuses to adopt a partially matching scheduled-mail table', function () {
    config()->set(
        'mail-notifications.storage.tables.scheduled_messages',
        'partial_scheduled_mail_messages',
    );
    Schema::create(
        'partial_scheduled_mail_messages',
        function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('factory_alias', 128);
            $table->unsignedSmallInteger('payload_version');
            $table->json('payload');
            $table->json('to_recipients');
            $table->string('status', 32);
            $table->timestampTz('scheduled_for');
            $table->timestampTz('available_at');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedSmallInteger('max_attempts');
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('locked_until')->nullable();
        },
    );
    $migration = require dirname(__DIR__, 2)
        .'/database/migrations/2026_07_30_000100_create_scheduled_mail_messages_table.php';

    expect(static fn () => $migration->up())
        ->toThrow(
            LogicException::class,
            'Existing scheduled mail table [partial_scheduled_mail_messages] is incompatible',
        );
});

it('refuses to adopt even a compatible unowned scheduled-mail table', function () {
    $connectionName = 'scheduled-mail-ownership-test';
    config()->set('database.connections.'.$connectionName, [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($connectionName);
    config()->set(
        'mail-notifications.storage.connection',
        $connectionName,
    );
    config()->set(
        'mail-notifications.storage.tables.scheduled_messages',
        'host_owned_scheduled_mail_messages',
    );
    $migrationPath = dirname(__DIR__, 2)
        .'/database/migrations/2026_07_30_000100_create_scheduled_mail_messages_table.php';
    $migration = require $migrationPath;
    $migration->up();
    DB::table('migrations')
        ->where(
            'migration',
            '2026_07_30_000100_create_scheduled_mail_messages_table',
        )
        ->delete();

    try {
        expect(static fn () => (require $migrationPath)->up())
            ->toThrow(
                LogicException::class,
                'cannot be adopted because the package migration',
            );
    } finally {
        DB::purge($connectionName);
    }
});

it('registers configured factories under the public container tag', function () {
    config()->set(
        'mail-notifications.extensions.scheduled_message_factories',
        [ScheduledTestFactory::class],
    );
    (new MailNotificationsServiceProvider(app()))->register();
    app()->forgetInstance(ScheduledMessageFactoryRegistry::class);

    expect(app(ScheduledMessageFactoryRegistry::class)->resolve(
        'test.scheduled',
        1,
    ))->toBeInstanceOf(ScheduledTestFactory::class);
});

it('schedules normalized recipients and supports pending mutations', function () {
    Event::fake([
        ScheduledMailScheduled::class,
        ScheduledMailRescheduled::class,
        ScheduledMailCancelled::class,
    ]);
    $scheduler = app(ScheduledMailScheduler::class);
    $message = $scheduler->schedule(scheduledMailRequest());

    expect($message)
        ->status->toBe(ScheduledMailStatus::Pending)
        ->attempts->toBe(0)
        ->to_recipients->toHaveCount(1)
        ->cc_recipients->toHaveCount(1)
        ->bcc_recipients->toHaveCount(1)
        ->and($message->to_recipients[0]['name'])->toBe('Replacement')
        ->and($message->available_at->equalTo($message->scheduled_for))
        ->toBeTrue()
        ->and($message->metadata)->toBe([
            'token' => '[REDACTED]',
            'safe' => 'visible-value',
        ]);

    $rescheduledFor = CarbonImmutable::now('UTC')->addHour();
    $rescheduled = $scheduler->reschedule($message->id, $rescheduledFor);

    expect($rescheduled->scheduled_for->equalTo($rescheduledFor))->toBeTrue()
        ->and($rescheduled->available_at->equalTo($rescheduledFor))->toBeTrue();

    $cancelled = $scheduler->cancel($message->id);

    expect($cancelled->status)->toBe(ScheduledMailStatus::Cancelled)
        ->and(fn () => $scheduler->reschedule(
            $message->id,
            $rescheduledFor->addHour(),
        ))->toThrow(ScheduledMailException::class, 'Only pending');
    Event::assertDispatched(ScheduledMailScheduled::class);
    Event::assertDispatched(ScheduledMailRescheduled::class);
    Event::assertDispatched(ScheduledMailCancelled::class);
});

it('supports a three-day provider submission lead time in UTC', function () {
    Event::fake([ScheduledMailScheduled::class]);
    $scheduledFor = CarbonImmutable::parse(
        '2026-08-02 15:00:00',
        'Europe/Sofia',
    );
    $availableAt = CarbonImmutable::parse(
        '2026-07-30 15:00:00',
        'Europe/Sofia',
    );

    $message = app(ScheduledMailScheduler::class)->schedule(
        scheduledMailRequest(
            scheduledFor: $scheduledFor,
            availableAt: $availableAt,
        ),
    );
    $claimed = app(ScheduledMailClaimer::class)->claim(1);
    $factoryData = ScheduledMessageData::fromModel($claimed[0]);

    expect($claimed)->toHaveCount(1)
        ->and($message->scheduled_for->toIso8601String())
        ->toBe('2026-08-02T12:00:00+00:00')
        ->and($message->available_at->toIso8601String())
        ->toBe('2026-07-30T12:00:00+00:00')
        ->and($factoryData->scheduledFor->toIso8601String())
        ->toBe('2026-08-02T12:00:00+00:00')
        ->and($factoryData->availableAt->toIso8601String())
        ->toBe('2026-07-30T12:00:00+00:00');
    Event::assertDispatched(
        ScheduledMailScheduled::class,
        static fn (ScheduledMailScheduled $event): bool => $event->messageId
            === $message->id
            && $event->scheduledFor->toIso8601String()
                === '2026-08-02T12:00:00+00:00'
            && $event->availableAt->toIso8601String()
                === '2026-07-30T12:00:00+00:00',
    );
});

it('rejects a submission instant later than intended delivery', function () {
    $scheduledFor = CarbonImmutable::now('UTC')->addDays(3);

    expect(fn () => scheduledMailRequest(
        scheduledFor: $scheduledFor,
        availableAt: $scheduledFor->addSecond(),
    ))->toThrow(
        InvalidArgumentException::class,
        'at or before its scheduled delivery time',
    )
        ->and(ScheduledMailMessage::query()->count())->toBe(0);
});

it('rejects corrupt persisted availability on the initial attempt', function () {
    $scheduledFor = CarbonImmutable::now('UTC');
    $message = app(ScheduledMailScheduler::class)->schedule(
        scheduledMailRequest(scheduledFor: $scheduledFor),
    );
    $corruptAvailability = $scheduledFor->addMinute();
    $message->update(['available_at' => $corruptAvailability]);
    CarbonImmutable::setTestNow($corruptAvailability);
    $claimed = app(ScheduledMailClaimer::class)->claim(1);

    expect($claimed)->toHaveCount(1)
        ->and($claimed[0]->attempts)->toBe(1)
        ->and(fn () => ScheduledMessageData::fromModel($claimed[0]))
        ->toThrow(
            InvalidArgumentException::class,
            'initial availability must be at or before',
        );
});

it('reschedules delivery and provider submission instants atomically', function () {
    Event::fake([ScheduledMailRescheduled::class]);
    $scheduler = app(ScheduledMailScheduler::class);
    $message = $scheduler->schedule(scheduledMailRequest());
    $scheduledFor = CarbonImmutable::parse(
        '2026-08-04 15:00:00',
        'Europe/Sofia',
    );
    $availableAt = $scheduledFor->subDays(3);

    $rescheduled = $scheduler->reschedule(
        $message->id,
        $scheduledFor,
        $availableAt,
    );

    expect($rescheduled->scheduled_for->toIso8601String())
        ->toBe('2026-08-04T12:00:00+00:00')
        ->and($rescheduled->available_at->toIso8601String())
        ->toBe('2026-08-01T12:00:00+00:00');
    Event::assertDispatched(
        ScheduledMailRescheduled::class,
        static fn (ScheduledMailRescheduled $event): bool => $event->messageId
            === $message->id
            && $event->previousAvailableAt->equalTo(
                CarbonImmutable::now('UTC'),
            )
            && $event->scheduledFor->toIso8601String()
                === '2026-08-04T12:00:00+00:00'
            && $event->availableAt->toIso8601String()
                === '2026-08-01T12:00:00+00:00',
    );

    $defaultedFor = CarbonImmutable::now('UTC')->addDays(7);
    $defaulted = $scheduler->reschedule($message->id, $defaultedFor);

    expect($defaulted->scheduled_for->equalTo($defaultedFor))->toBeTrue()
        ->and($defaulted->available_at->equalTo($defaultedFor))->toBeTrue()
        ->and(fn () => $scheduler->reschedule(
            $message->id,
            $defaultedFor->addDay(),
            $defaultedFor->addDay()->addSecond(),
        ))->toThrow(
            ScheduledMailException::class,
            'at or before its scheduled delivery time',
        )
        ->and($message->fresh()?->scheduled_for->equalTo($defaultedFor))
        ->toBeTrue()
        ->and($message->fresh()?->available_at->equalTo($defaultedFor))
        ->toBeTrue();
});

it('atomically replaces every host-owned field while preserving attempts', function () {
    Event::fake([ScheduledMailReplaced::class]);
    $scheduler = app(ScheduledMailScheduler::class);
    $message = $scheduler->schedule(scheduledMailRequest(maxAttempts: 4));
    $lastAttemptAt = CarbonImmutable::now('UTC')->subMinute();
    $message->update([
        'attempts' => 1,
        'last_attempt_at' => $lastAttemptAt,
        'last_error' => RuntimeException::class,
    ]);
    $replacementTime = CarbonImmutable::now('UTC')->addHours(2);
    $replacementAvailability = $replacementTime->subHour();
    $replacement = new ScheduleMailData(
        factoryAlias: 'test.scheduled',
        payloadVersion: 1,
        payload: ['body' => 'Replacement body'],
        recipients: new ScheduledRecipients(
            to: [new Recipient('replacement@example.test', 'Replacement')],
            cc: [new Recipient('copy-replacement@example.test')],
        ),
        scheduledFor: $replacementTime,
        metadata: [
            'token' => 'replacement-secret',
            'safe' => 'replacement-visible',
        ],
        maxAttempts: 3,
        availableAt: $replacementAvailability,
    );

    $replaced = $scheduler->replacePending($message->id, $replacement);

    expect($replaced)
        ->id->toBe($message->id)
        ->status->toBe(ScheduledMailStatus::Pending)
        ->attempts->toBe(1)
        ->max_attempts->toBe(3)
        ->payload->toBe(['body' => 'Replacement body'])
        ->last_error->toBeNull()
        ->claim_token->toBeNull()
        ->locked_until->toBeNull()
        ->and($replaced->last_attempt_at?->equalTo($lastAttemptAt))->toBeTrue()
        ->and($replaced->scheduled_for->equalTo($replacementTime))->toBeTrue()
        ->and($replaced->available_at->equalTo(
            $replacementAvailability,
        ))->toBeTrue()
        ->and($replaced->to_recipients)->toEqual([[
            'email' => 'replacement@example.test',
            'name' => 'Replacement',
        ]])
        ->and($replaced->cc_recipients)->toEqual([[
            'email' => 'copy-replacement@example.test',
            'name' => null,
        ]])
        ->and($replaced->metadata)->toEqual([
            'token' => '[REDACTED]',
            'safe' => 'replacement-visible',
        ]);
    Event::assertDispatched(
        ScheduledMailReplaced::class,
        static fn (ScheduledMailReplaced $event): bool => $event->messageId
            === $message->id
            && $event->factoryAlias === 'test.scheduled'
            && $event->payloadVersion === 1
            && $event->previousScheduledFor->equalTo(
                CarbonImmutable::now('UTC'),
            )
            && $event->previousAvailableAt->equalTo(
                CarbonImmutable::now('UTC'),
            )
            && $event->scheduledFor->equalTo($replacementTime)
            && $event->availableAt->equalTo($replacementAvailability),
    );
});

it('bounds payloads and recipients before invoking host factory validation', function () {
    $scheduler = app(ScheduledMailScheduler::class);
    $message = $scheduler->schedule(scheduledMailRequest());
    $factory = new class implements ScheduledMessageFactory
    {
        public int $validationCalls = 0;

        public function alias(): string
        {
            return 'test.input-order';
        }

        public function supportsVersion(int $version): bool
        {
            return $version === 1;
        }

        public function validate(int $version, array $payload): void
        {
            $this->validationCalls++;
        }

        public function make(
            ScheduledMessageData $message,
        ): Mailable {
            return new ScheduledTestMail('Input order');
        }
    };
    app()->instance(
        ScheduledMessageFactoryRegistry::class,
        new ScheduledMessageFactoryRegistry([$factory]),
    );
    app()->forgetInstance(ScheduledMailScheduler::class);
    config()->set('mail-notifications.scheduling.max_payload_bytes', 16);
    $oversized = new ScheduleMailData(
        factoryAlias: 'test.input-order',
        payloadVersion: 1,
        payload: ['body' => str_repeat('x', 100)],
        recipients: new ScheduledRecipients([
            new Recipient('bounded@example.test'),
        ]),
        scheduledFor: CarbonImmutable::now('UTC'),
    );

    expect(fn () => app(ScheduledMailScheduler::class)->schedule($oversized))
        ->toThrow(ScheduledMailException::class, 'byte limit')
        ->and(fn () => app(ScheduledMailScheduler::class)->replacePending(
            $message->id,
            $oversized,
        ))->toThrow(ScheduledMailException::class, 'byte limit')
        ->and($factory->validationCalls)->toBe(0)
        ->and($message->fresh()?->payload)->toBe([
            'body' => 'Scheduled body',
        ]);

    config()->set('mail-notifications.scheduling.max_payload_bytes', 65_536);
    config()->set('mail-notifications.scheduling.max_recipients', 2);
    $excessRecipients = new ScheduleMailData(
        factoryAlias: 'test.input-order',
        payloadVersion: 1,
        payload: ['body' => 'bounded'],
        recipients: new ScheduledRecipients([
            new Recipient('one@example.test'),
            new Recipient('two@example.test'),
            new Recipient('three@example.test'),
        ]),
        scheduledFor: CarbonImmutable::now('UTC'),
    );

    expect(fn () => app(ScheduledMailScheduler::class)->schedule(
        $excessRecipients,
    ))->toThrow(ScheduledMailException::class, 'recipient')
        ->and($factory->validationCalls)->toBe(0);
});

it('never replaces processing or terminal scheduled messages', function (
    ScheduledMailStatus $status,
) {
    $scheduler = app(ScheduledMailScheduler::class);
    $message = $scheduler->schedule(scheduledMailRequest());
    $message->update(['status' => $status]);

    expect(fn () => $scheduler->replacePending(
        $message->id,
        scheduledMailRequest(
            scheduledFor: CarbonImmutable::now('UTC')->addHour(),
        ),
    ))->toThrow(ScheduledMailException::class, 'Only pending')
        ->and($message->fresh()?->payload)->toBe([
            'body' => 'Scheduled body',
        ]);
})->with([
    'processing' => ScheduledMailStatus::Processing,
    'sent' => ScheduledMailStatus::Sent,
    'failed' => ScheduledMailStatus::Failed,
    'cancelled' => ScheduledMailStatus::Cancelled,
]);

it('rejects replacement attempt ceilings that would strand a pending row', function () {
    $scheduler = app(ScheduledMailScheduler::class);
    $message = $scheduler->schedule(scheduledMailRequest(maxAttempts: 3));
    $message->update(['attempts' => 2]);

    expect(fn () => $scheduler->replacePending(
        $message->id,
        scheduledMailRequest(maxAttempts: 2),
    ))->toThrow(
        ScheduledMailException::class,
        'must exceed attempts already made',
    )
        ->and($message->fresh()?->max_attempts)->toBe(3);
});

it('keeps replacement-event listeners observational', function () {
    Event::listen(
        ScheduledMailReplaced::class,
        static fn (): never => throw new RuntimeException(
            'Host replacement listener failed.',
        ),
    );
    $scheduler = app(ScheduledMailScheduler::class);
    $message = $scheduler->schedule(scheduledMailRequest());
    $scheduledFor = CarbonImmutable::now('UTC')->addHour();

    $replaced = $scheduler->replacePending(
        $message->id,
        scheduledMailRequest(scheduledFor: $scheduledFor),
    );

    expect($replaced->scheduled_for->equalTo($scheduledFor))->toBeTrue()
        ->and($replaced->status)->toBe(ScheduledMailStatus::Pending);
});

it('dispatches scheduling events only after the owning transaction commits', function () {
    $connectionName = 'scheduled-mail-event-test';
    config()->set('database.connections.'.$connectionName, [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($connectionName);
    config()->set('mail-notifications.storage.connection', $connectionName);
    $migration = require dirname(__DIR__, 2)
        .'/database/migrations/2026_07_30_000100_create_scheduled_mail_messages_table.php';
    $migration->up();
    $observed = [];
    Event::listen(
        ScheduledMailScheduled::class,
        static function (ScheduledMailScheduled $event) use (
            &$observed,
        ): void {
            $observed[] = $event->messageId;
        },
    );
    $connection = DB::connection($connectionName);
    $connection->beginTransaction();

    try {
        $message = app(ScheduledMailScheduler::class)
            ->schedule(scheduledMailRequest());

        expect($observed)->toBe([]);

        $connection->commit();

        expect($observed)->toBe([$message->id]);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        DB::purge($connectionName);
    }
});

it('claims once and sends outside the claiming transaction', function () {
    Event::fake([
        ScheduledMailClaimed::class,
        ScheduledMailSent::class,
    ]);
    $transactionLevel = (new ScheduledMailMessage)->getConnection()
        ->transactionLevel();
    $factory = new class($transactionLevel) implements ScheduledMessageFactory
    {
        public function __construct(
            private readonly int $transactionLevel,
        ) {}

        public function alias(): string
        {
            return 'test.outside-transaction';
        }

        public function supportsVersion(int $version): bool
        {
            return $version === 1;
        }

        public function validate(int $version, array $payload): void {}

        public function make(
            ScheduledMessageData $message,
        ): Mailable {
            if ((new ScheduledMailMessage)->getConnection()
                ->transactionLevel() !== $this->transactionLevel) {
                throw new RuntimeException('Delivery ran in a transaction.');
            }

            return new ScheduledTestMail('Outside transaction');
        }
    };
    app()->instance(
        ScheduledMessageFactoryRegistry::class,
        new ScheduledMessageFactoryRegistry([$factory]),
    );
    app()->forgetInstance(ScheduledMailScheduler::class);
    $outsideTransaction = app(ScheduledMailScheduler::class)->schedule(new ScheduleMailData(
        factoryAlias: 'test.outside-transaction',
        payloadVersion: 1,
        payload: ['body' => 'Outside transaction'],
        recipients: new ScheduledRecipients([
            new Recipient('outside@example.test'),
        ]),
        scheduledFor: CarbonImmutable::now('UTC'),
    ));

    expect(app(ScheduledMailProcessor::class)->process())->toBe(1)
        ->and(app(ScheduledMailClaimer::class)->claim())->toBe([]);
    $outsideTransaction->refresh();

    expect($outsideTransaction)
        ->status->toBe(ScheduledMailStatus::Sent)
        ->attempts->toBe(1)
        ->claim_token->toBeNull();
    Event::assertDispatched(ScheduledMailClaimed::class);
    Event::assertDispatched(ScheduledMailSent::class);
});

it('starts each claim lease immediately before its send attempt', function () {
    $factory = new class implements ScheduledMessageFactory
    {
        /**
         * @var list<ScheduledMailStatus>
         */
        public array $observedOtherStatuses = [];

        private int $deliveryCount = 0;

        public function alias(): string
        {
            return 'test.sequential-leases';
        }

        public function supportsVersion(int $version): bool
        {
            return $version === 1;
        }

        public function validate(int $version, array $payload): void {}

        public function make(
            ScheduledMessageData $message,
        ): Mailable {
            $otherMessage = ScheduledMailMessage::query()
                ->where('factory_alias', $this->alias())
                ->where('id', '!=', $message->id)
                ->firstOrFail();
            $this->observedOtherStatuses[] = $otherMessage->status;
            $this->deliveryCount++;

            if ($this->deliveryCount === 1) {
                CarbonImmutable::setTestNow(
                    CarbonImmutable::now('UTC')->addSeconds(30),
                );
            }

            return new ScheduledTestMail('Sequential lease');
        }
    };
    app()->instance(
        ScheduledMessageFactoryRegistry::class,
        new ScheduledMessageFactoryRegistry([$factory]),
    );
    app()->forgetInstance(ScheduledMailScheduler::class);
    app()->forgetInstance(ScheduledMailProcessor::class);
    $scheduler = app(ScheduledMailScheduler::class);
    $first = $scheduler->schedule(scheduledMailRequest(
        alias: 'test.sequential-leases',
    ));
    $second = $scheduler->schedule(scheduledMailRequest(
        alias: 'test.sequential-leases',
    ));

    expect(app(ScheduledMailProcessor::class)->process(2))->toBe(2);

    $messages = ScheduledMailMessage::query()
        ->whereKey([$first->id, $second->id])
        ->orderBy('last_attempt_at')
        ->get();
    $attemptTimes = $messages
        ->map(
            static fn (ScheduledMailMessage $message): ?string => $message
                ->last_attempt_at
                ?->format('Y-m-d H:i:s'),
        )
        ->all();

    expect($factory->observedOtherStatuses)->toBe([
        ScheduledMailStatus::Pending,
        ScheduledMailStatus::Sent,
    ])
        ->and($messages->pluck('status')->all())->toBe([
            ScheduledMailStatus::Sent,
            ScheduledMailStatus::Sent,
        ])
        ->and($attemptTimes)->toBe([
            '2026-07-30 12:00:00',
            '2026-07-30 12:00:30',
        ]);
});

it('fails Laravel-cancelled scheduled sends terminally', function () {
    Event::listen(MessageSending::class, static fn (): false => false);
    $message = app(ScheduledMailScheduler::class)->schedule(
        scheduledMailRequest(maxAttempts: 3),
    );

    expect(app(ScheduledMailProcessor::class)->process(1))->toBe(1);
    $message->refresh();

    expect($message)
        ->status->toBe(ScheduledMailStatus::Failed)
        ->attempts->toBe(1)
        ->last_error->toBe(MailDeliveryCancelled::class)
        ->claim_token->toBeNull()
        ->sent_at->toBeNull()
        ->and($message->failed_at)->not->toBeNull();
});

it('honors the mailer selected by a factory-created Mailable', function () {
    $factory = new class implements ScheduledMessageFactory
    {
        public function alias(): string
        {
            return 'test.selected-mailer';
        }

        public function supportsVersion(int $version): bool
        {
            return $version === 1;
        }

        public function validate(int $version, array $payload): void {}

        public function make(
            ScheduledMessageData $message,
        ): Mailable {
            return (new ScheduledTestMail('Selected mailer'))
                ->mailer('smtp-test');
        }
    };
    app()->instance(
        ScheduledMessageFactoryRegistry::class,
        new ScheduledMessageFactoryRegistry([$factory]),
    );
    app()->forgetInstance(ScheduledMailScheduler::class);
    app()->forgetInstance(ScheduledMailProcessor::class);
    $message = app(ScheduledMailScheduler::class)->schedule(new ScheduleMailData(
        factoryAlias: 'test.selected-mailer',
        payloadVersion: 1,
        payload: ['body' => 'Selected mailer'],
        recipients: new ScheduledRecipients([
            new Recipient('selected@example.test'),
        ]),
        scheduledFor: CarbonImmutable::now('UTC'),
    ));

    expect(app(ScheduledMailProcessor::class)->process())->toBe(1);
    $message->refresh();
    $mail = app('mail.manager');

    expect($message->status)->toBe(ScheduledMailStatus::Sent)
        ->and($mail->mailer('smtp-test')->getSymfonyTransport()->messages())
        ->toHaveCount(1)
        ->and($mail->mailer('array')->getSymfonyTransport()->messages())
        ->toHaveCount(0);
});

it('replaces factory Mailable recipients with the persisted scheduled envelope', function () {
    $factory = new class implements ScheduledMessageFactory
    {
        public function alias(): string
        {
            return 'test.persisted-envelope';
        }

        public function supportsVersion(int $version): bool
        {
            return $version === 1;
        }

        public function validate(int $version, array $payload): void {}

        public function make(
            ScheduledMessageData $message,
        ): Mailable {
            $mailable = new class extends Mailable implements TrackableMessage
            {
                use TracksMailDelivery;

                public function envelope(): Envelope
                {
                    return new Envelope(
                        to: ['factory-to@example.test'],
                        cc: ['factory-cc@example.test'],
                        bcc: ['factory-bcc@example.test'],
                        subject: 'Factory recipient envelope',
                    );
                }

                public function content(): Content
                {
                    return new Content(htmlString: 'Scheduled body');
                }

                public function trackingContext(): TrackingContext
                {
                    return TrackingContext::forCategory(
                        'test.persisted-envelope',
                    );
                }
            };
            $mailable->withSymfonyMessage(
                static function (Email $email): void {
                    $email->addTo(new SymfonyAddress(
                        'factory-callback-to@example.test',
                    ));
                    $email->addCc(new SymfonyAddress(
                        'factory-callback-cc@example.test',
                    ));
                    $email->addBcc(new SymfonyAddress(
                        'factory-callback-bcc@example.test',
                    ));
                },
            );

            return $mailable;
        }
    };
    app()->instance(
        ScheduledMessageFactoryRegistry::class,
        new ScheduledMessageFactoryRegistry([$factory]),
    );
    app()->forgetInstance(ScheduledMailScheduler::class);
    app()->forgetInstance(ScheduledMailProcessor::class);
    app(ScheduledMailScheduler::class)->schedule(new ScheduleMailData(
        factoryAlias: 'test.persisted-envelope',
        payloadVersion: 1,
        payload: ['body' => 'Scheduled body'],
        recipients: new ScheduledRecipients(
            to: [new Recipient('persisted-to@example.test', 'Persisted To')],
            cc: [new Recipient('persisted-cc@example.test', 'Persisted Cc')],
            bcc: [new Recipient(
                'persisted-bcc@example.test',
                'Persisted Bcc',
            )],
        ),
        scheduledFor: CarbonImmutable::now('UTC'),
    ));

    expect(app(ScheduledMailProcessor::class)->process())->toBe(1);

    $wireMessage = app('mail.manager')
        ->mailer('array')
        ->getSymfonyTransport()
        ->messages()
        ->sole()
        ->getOriginalMessage();

    expect($wireMessage)->toBeInstanceOf(Email::class);

    if (! $wireMessage instanceof Email) {
        throw new RuntimeException('Expected the array transport to contain an email.');
    }

    $notification = MailNotification::query()->sole();

    expect(array_map(
        static fn (SymfonyAddress $address): array => [
            $address->getAddress(),
            $address->getName(),
        ],
        $wireMessage->getTo(),
    ))->toBe([['persisted-to@example.test', 'Persisted To']])
        ->and(array_map(
            static fn (SymfonyAddress $address): array => [
                $address->getAddress(),
                $address->getName(),
            ],
            $wireMessage->getCc(),
        ))->toBe([['persisted-cc@example.test', 'Persisted Cc']])
        ->and(array_map(
            static fn (SymfonyAddress $address): array => [
                $address->getAddress(),
                $address->getName(),
            ],
            $wireMessage->getBcc(),
        ))->toBe([['persisted-bcc@example.test', 'Persisted Bcc']])
        ->and($notification->to_recipients)->toEqual([[
            'email' => 'persisted-to@example.test',
            'name' => 'Persisted To',
        ]])
        ->and($notification->cc_recipients)->toEqual([[
            'email' => 'persisted-cc@example.test',
            'name' => 'Persisted Cc',
        ]])
        ->and($notification->bcc_recipients)->toEqual([[
            'email' => 'persisted-bcc@example.test',
            'name' => 'Persisted Bcc',
        ]]);
});

it('keeps Laravel global recipient interception authoritative for scheduled mail', function () {
    Mail::alwaysTo('safe-inbox@example.test', 'Safe Inbox');
    app(ScheduledMailScheduler::class)->schedule(scheduledMailRequest());

    expect(app(ScheduledMailProcessor::class)->process())->toBe(1);

    $wireMessage = app('mail.manager')
        ->mailer('array')
        ->getSymfonyTransport()
        ->messages()
        ->sole()
        ->getOriginalMessage();

    expect($wireMessage)->toBeInstanceOf(Email::class);

    if (! $wireMessage instanceof Email) {
        throw new RuntimeException('Expected the array transport to contain an email.');
    }

    expect(array_map(
        static fn (SymfonyAddress $address): array => [
            $address->getAddress(),
            $address->getName(),
        ],
        $wireMessage->getTo(),
    ))->toBe([['safe-inbox@example.test', 'Safe Inbox']])
        ->and($wireMessage->getCc())->toBe([])
        ->and($wireMessage->getBcc())->toBe([]);
});

it('fails deterministic factory preparation terminally without raw failure text', function () {
    Event::fake([ScheduledMailFailed::class]);
    $message = app(ScheduledMailScheduler::class)->schedule(
        scheduledMailRequest(
            alias: 'test.scheduled-failure',
            maxAttempts: 2,
        ),
    );

    expect(app(ScheduledMailProcessor::class)->process())->toBe(1);
    $message->refresh();

    expect($message)
        ->status->toBe(ScheduledMailStatus::Failed)
        ->attempts->toBe(1)
        ->last_error->toBe(RuntimeException::class)
        ->claim_token->toBeNull()
        ->and($message->last_error)->not->toContain('raw provider secret');
    Event::assertDispatched(ScheduledMailFailed::class);
});

it('retries transport failures deterministically and exhausts attempts', function () {
    Event::fake([
        ScheduledMailRetrying::class,
        ScheduledMailFailed::class,
    ]);
    $failingMailer = new class implements Mailer
    {
        public function to(mixed $users): PendingMail
        {
            throw new RuntimeException('Not used.');
        }

        public function cc(mixed $users): PendingMail
        {
            throw new RuntimeException('Not used.');
        }

        public function bcc(mixed $users): PendingMail
        {
            throw new RuntimeException('Not used.');
        }

        public function raw(mixed $text, mixed $callback): ?SentMessage
        {
            throw new RuntimeException('Not used.');
        }

        public function send(
            mixed $view,
            array $data = [],
            mixed $callback = null,
        ): ?SentMessage {
            throw new RuntimeException(
                'raw transport secret must never be persisted',
            );
        }

        public function sendNow(
            mixed $mailable,
            array $data = [],
            mixed $callback = null,
        ): ?SentMessage {
            throw new RuntimeException('Not used.');
        }
    };
    app()->instance(
        MailFactory::class,
        new class($failingMailer) implements MailFactory
        {
            public function __construct(
                private readonly Mailer $mailer,
            ) {}

            public function mailer(mixed $name = null): Mailer
            {
                return $this->mailer;
            }
        },
    );
    app()->forgetInstance(ScheduledMailProcessor::class);
    $message = app(ScheduledMailScheduler::class)->schedule(
        scheduledMailRequest(
            maxAttempts: 2,
        ),
    );

    expect(app(ScheduledMailProcessor::class)->process())->toBe(1);
    $message->refresh();

    expect($message)
        ->status->toBe(ScheduledMailStatus::Pending)
        ->attempts->toBe(1)
        ->last_error->toBe(RuntimeException::class)
        ->and($message->available_at->equalTo(
            CarbonImmutable::now('UTC')->addSeconds(60),
        ))->toBeTrue()
        ->and($message->last_error)->not->toContain('raw transport secret');
    Event::assertDispatched(ScheduledMailRetrying::class);

    CarbonImmutable::setTestNow(
        CarbonImmutable::now('UTC')->addSeconds(60),
    );
    expect(app(ScheduledMailProcessor::class)->process())->toBe(1);
    $message->refresh();

    expect($message)
        ->status->toBe(ScheduledMailStatus::Failed)
        ->attempts->toBe(2)
        ->claim_token->toBeNull();
    Event::assertDispatched(ScheduledMailFailed::class);
});

it('recovers expired claims without incrementing attempts', function () {
    Event::fake([
        ScheduledMailRecovered::class,
        ScheduledMailRetrying::class,
    ]);
    $message = app(ScheduledMailScheduler::class)
        ->schedule(scheduledMailRequest());
    app(ScheduledMailClaimer::class)->claim();
    $message->refresh();
    $message->update([
        'locked_until' => CarbonImmutable::now('UTC')->subSecond(),
    ]);

    expect(app(ScheduledMailRecovery::class)->recover())->toBe(1);
    $message->refresh();

    expect($message)
        ->status->toBe(ScheduledMailStatus::Pending)
        ->attempts->toBe(1)
        ->claim_token->toBeNull()
        ->last_error->toBe('claim_expired')
        ->and($message->available_at->equalTo(
            CarbonImmutable::now('UTC')->addSeconds(60),
        ))->toBeTrue();
    Event::assertDispatched(ScheduledMailRecovered::class);
    Event::assertDispatched(ScheduledMailRetrying::class);
});

it('rejects stale claim-token finalization', function () {
    $message = app(ScheduledMailScheduler::class)
        ->schedule(scheduledMailRequest());
    app(ScheduledMailClaimer::class)->claim();
    $message->refresh();
    $staleToken = $message->claim_token;
    $message->update(['claim_token' => fake()->uuid()]);

    expect($staleToken)->toBeString()
        ->and(app(ScheduledMailFinalizer::class)->markSent(
            $message->id,
            $staleToken,
        ))->toBeFalse()
        ->and($message->fresh()?->status)->toBe(
            ScheduledMailStatus::Processing,
        );
});

it('exposes bounded process and recovery commands', function () {
    Mail::fake();
    app(ScheduledMailScheduler::class)->schedule(scheduledMailRequest());

    $this->artisan('nvl:mail-notifications:process-scheduled', [
        '--limit' => 1,
    ])->expectsOutputToContain('Processed 1 scheduled mail claim(s).')
        ->assertSuccessful();
    $this->artisan('nvl:mail-notifications:recover-scheduled', [
        '--limit' => 1,
    ])->expectsOutputToContain('Recovered 0 scheduled mail claim(s).')
        ->assertSuccessful();
});

it('rejects invalid scheduled command limits', function (
    string $command,
    mixed $limit,
) {
    $this->artisan($command, [
        '--limit' => $limit,
    ])->expectsOutputToContain(
        'The --limit option must be an integer between 1 and 1000.',
    )->assertExitCode(Command::INVALID);
})->with([
    'process zero' => [
        'nvl:mail-notifications:process-scheduled',
        0,
    ],
    'process malformed' => [
        'nvl:mail-notifications:process-scheduled',
        'many',
    ],
    'recovery above maximum' => [
        'nvl:mail-notifications:recover-scheduled',
        1_001,
    ],
    'recovery boolean' => [
        'nvl:mail-notifications:recover-scheduled',
        true,
    ],
]);
