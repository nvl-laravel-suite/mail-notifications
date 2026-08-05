<?php

declare(strict_types=1);

use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\ManuallyFailedException;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Events\MailTrackingFailed;
use Nvl\MailNotifications\Events\MailTrackingStarted;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\TrackingRuntime;
use Nvl\MailNotifications\Support\TrackingRuntimeBridge;
use Nvl\MailNotifications\Tests\Fixtures\QueuedTrackedMail;
use Nvl\MailNotifications\Tests\Fixtures\QueuedTrackedMailWithFailureHook;
use Nvl\MailNotifications\Tests\Fixtures\TestTrackable;
use Nvl\MailNotifications\Tests\Fixtures\UnavailableTrackingLifecycle;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\MailNotifications\ValueObjects\TransitionResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;

/**
 * Build the same Laravel job that a queued Mailable serializes.
 */
function queuedMailFailureJob(Mailable $mailable): SendQueuedMailable
{
    $method = new ReflectionMethod($mailable, 'newQueuedJob');
    $job = $method->invoke($mailable);

    if (! $job instanceof SendQueuedMailable) {
        throw new RuntimeException('The queued Mailable fixture produced an unexpected job.');
    }

    return $job;
}

/**
 * Read the non-sensitive queue reference from one serialized test job.
 */
function queuedMailFailureReference(SendQueuedMailable $job): string
{
    $property = new ReflectionProperty(
        $job->mailable,
        'mailTrackingQueueReference',
    );
    $reference = $property->getValue($job->mailable);

    if (! is_string($reference) || ! Str::isUuid($reference)) {
        throw new RuntimeException('The queued Mailable has no valid queue reference.');
    }

    return $reference;
}

/**
 * Configure durable database-queue serialization for one test.
 */
function configureQueuedMailFailureDatabaseQueue(): void
{
    config()->set('queue.default', 'mail-failure-database');
    config()->set('queue.connections.mail-failure-database', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'mail_failure_test_jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);
    Schema::create('mail_failure_test_jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

/**
 * Reboot tracking after changing host integration bindings.
 */
function rebootQueuedMailFailureTracking(): void
{
    app()->forgetInstance(MailNotificationNotifiableTypeRegistry::class);
    app()->forgetInstance(TrackingLifecycle::class);
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));
}

it('persists one idempotent failed row when a queued Mailable fails before send', function () {
    Event::fake([
        MailTrackingStarted::class,
        MailTrackingFailed::class,
    ]);
    config()->set('mail-notifications.notifiable_types', [
        'test-account' => TestTrackable::class,
    ]);
    rebootQueuedMailFailureTracking();

    $serializedJob = serialize(queuedMailFailureJob(
        (new QueuedTrackedMail)
            ->forNotifiable(new TestTrackable('pre-send-account'))
            ->withTrackingMetadata([
                'workflow' => 'pre-send-failure',
                'api_token' => 'secret-value',
            ])
            ->to('failed-before-send@example.test'),
    ));
    $exception = new RuntimeException('sensitive queue failure details');

    unserialize($serializedJob)->failed($exception);
    unserialize($serializedJob)->failed($exception);

    $notification = MailNotification::query()->sole();

    expect($notification)
        ->id->toBe($notification->queue_reference)
        ->status->toBe(MailDeliveryStatus::Failed)
        ->mailer->toBe('array')
        ->message_category->toBe('test.queued-message')
        ->subject->toBe('Queued tracked message')
        ->from_email->toBe('sender@example.test')
        ->primary_recipient_email->toBe('failed-before-send@example.test')
        ->notifiable_type->toBe('test-account')
        ->notifiable_id->toBe('pre-send-account')
        ->and($notification->metadata)->toMatchArray([
            'workflow' => 'pre-send-failure',
            'api_token' => '[REDACTED]',
            'failure' => [
                'exception' => RuntimeException::class,
            ],
        ])
        ->and(json_encode($notification->metadata))->not->toContain(
            'sensitive queue failure details',
        );

    Event::assertDispatchedTimes(MailTrackingStarted::class, 1);
    Event::assertDispatchedTimes(MailTrackingFailed::class, 1);
});

it('does not duplicate a failed send attempt when Laravel invokes its terminal hook', function () {
    $serializedJob = serialize(queuedMailFailureJob(
        (new QueuedTrackedMail)->to('failed-at-send@example.test'),
    ));
    $workerJob = unserialize($serializedJob);
    $callbackJob = unserialize($serializedJob);
    $exception = new RuntimeException('transport-adjacent failure');

    Event::listen(
        MessageSending::class,
        static fn (): never => throw $exception,
    );

    expect(
        fn () => $workerJob->handle(app(MailFactory::class)),
    )->toThrow(RuntimeException::class, 'transport-adjacent failure');

    $callbackJob->failed($exception);
    unserialize($serializedJob)->failed($exception);

    $notification = MailNotification::query()->sole();

    expect($notification)
        ->status->toBe(MailDeliveryStatus::Failed)
        ->queue_reference->not->toBeNull()
        ->and($notification->correlation_id)
        ->not->toBe($notification->queue_reference)
        ->and(MailNotification::query()->count())->toBe(1);
});

it('does not fail another worker pending attempt selected only by queue identity', function () {
    $serializedJob = serialize(queuedMailFailureJob(
        (new QueuedTrackedMail)->to('duplicate-worker@example.test'),
    ));
    $callbackJob = unserialize($serializedJob);
    $queueReference = queuedMailFailureReference($callbackJob);
    $lifecycle = app(DatabaseTrackingLifecycle::class);
    $workerCorrelation = (string) Str::uuid();
    $workerAttempt = $lifecycle->begin(new PreparedMessage(
        correlationId: $workerCorrelation,
        mailer: 'array',
        context: TrackingContext::forCategory('test.queued-message'),
        from: new Recipient('sender@example.test', 'Example Sender'),
        to: [new Recipient('duplicate-worker@example.test')],
        subject: 'Queued tracked message',
        queueReference: $queueReference,
    ));

    $callbackJob->failed(new RuntimeException('another worker failed'));
    $lifecycle->accepted(
        $workerAttempt,
        new ProviderAcceptance(
            new ProviderMessageId('array', 'duplicate-worker-accepted'),
        ),
    );

    $worker = MailNotification::query()->findOrFail($workerCorrelation);
    $fallback = MailNotification::query()->findOrFail($queueReference);

    expect($worker)
        ->status->toBe(MailDeliveryStatus::Accepted)
        ->provider_message_id->toBe('duplicate-worker-accepted')
        ->and($fallback->status)->toBe(MailDeliveryStatus::Failed)
        ->and(MailNotification::query()->count())->toBe(2);
});

it('does not replace provider acceptance when later queue middleware fails', function () {
    $serializedJob = serialize(queuedMailFailureJob(
        (new QueuedTrackedMail)->to('accepted-before-hook@example.test'),
    ));

    unserialize($serializedJob)->handle(app(MailFactory::class));
    unserialize($serializedJob)->failed(
        new RuntimeException('middleware failed after delivery'),
    );

    expect(MailNotification::query()->sole())
        ->status->toBe(MailDeliveryStatus::Accepted)
        ->accepted_at->not->toBeNull()
        ->and(MailNotification::query()->count())->toBe(1);
});

it('keeps queue identity off an original Mailable reused synchronously', function () {
    configureQueuedMailFailureDatabaseQueue();
    $mail = (new QueuedTrackedMail)->to('queued-copy@example.test');

    $mail->queue(app(QueueFactory::class));
    $mail->to('synchronous-reuse@example.test');
    $mail->send(app(MailFactory::class));

    $queue = app(QueueManager::class)->connection('mail-failure-database');
    $job = $queue->pop('default');

    expect($job)->not->toBeNull();

    $job?->fail(new RuntimeException('queued copy failed before send'));

    $accepted = MailNotification::query()
        ->where('status', MailDeliveryStatus::Accepted)
        ->sole();
    $failed = MailNotification::query()
        ->where('status', MailDeliveryStatus::Failed)
        ->sole();

    expect($accepted->queue_reference)->toBeNull()
        ->and($accepted->to_recipients)->toHaveCount(2)
        ->and($failed->queue_reference)->not->toBeNull()
        ->and($failed->correlation_id)->toBe($failed->queue_reference)
        ->and(MailNotification::query()->count())->toBe(2);
});

it('normalizes a manually failed queued Mailable without an exception', function () {
    $job = queuedMailFailureJob(
        (new QueuedTrackedMail)->to('manual-failure@example.test'),
    );

    $job->failed(null);

    expect(MailNotification::query()->sole())
        ->status->toBe(MailDeliveryStatus::Failed)
        ->and(MailNotification::query()->sole()->metadata)
        ->toHaveKey('failure.exception', ManuallyFailedException::class);
});

it('recovers fail-closed bootstrap failure with preserved fluent context', function () {
    config()->set('mail-notifications.notifiable_types', [
        'test-account' => TestTrackable::class,
    ]);
    app()->forgetInstance(MailNotificationNotifiableTypeRegistry::class);
    $database = app(DatabaseTrackingLifecycle::class);
    $lifecycle = new class($database) implements TrackingLifecycle
    {
        public function __construct(
            private readonly DatabaseTrackingLifecycle $database,
        ) {}

        public function begin(PreparedMessage $message): TrackingAttempt
        {
            throw new RuntimeException('Tracking bootstrap is temporarily unavailable.');
        }

        public function accepted(
            TrackingAttempt $attempt,
            ProviderAcceptance $acceptance,
        ): void {
            $this->database->accepted($attempt, $acceptance);
        }

        public function failed(
            TrackingAttempt $attempt,
            Throwable $exception,
        ): void {
            $this->database->failed($attempt, $exception);
        }

        public function queuedFailure(
            PreparedMessage $message,
            Throwable $exception,
        ): void {
            $this->database->queuedFailure($message, $exception);
        }

        public function apply(
            VerifiedDeliveryEvent $event,
        ): TransitionResult {
            return $this->database->apply($event);
        }
    };
    app()->instance(TrackingLifecycle::class, $lifecycle);
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));
    $job = queuedMailFailureJob(
        (new QueuedTrackedMail)
            ->forNotifiable(new TestTrackable('bootstrap-account'))
            ->withTrackingMetadata([
                'workflow' => 'bootstrap-recovery',
                'password' => 'secret-value',
            ])
            ->to('bootstrap-failure@example.test'),
    );
    $exception = new RuntimeException(
        'Tracking bootstrap is temporarily unavailable.',
    );

    expect(
        fn () => $job->handle(app(MailFactory::class)),
    )->toThrow(
        RuntimeException::class,
        'Tracking bootstrap is temporarily unavailable.',
    );

    expect(fn () => $job->failed($exception))->not->toThrow(Throwable::class);

    $notification = MailNotification::query()->sole();

    expect($notification)
        ->status->toBe(MailDeliveryStatus::Failed)
        ->notifiable_type->toBe('test-account')
        ->notifiable_id->toBe('bootstrap-account')
        ->primary_recipient_email->toBe('bootstrap-failure@example.test')
        ->and($notification->metadata)->toMatchArray([
            'workflow' => 'bootstrap-recovery',
            'password' => '[REDACTED]',
        ]);
});

it('allows host failure hooks to compose even when tracking synchronization is unavailable', function () {
    app()->instance(
        TrackingLifecycle::class,
        new UnavailableTrackingLifecycle,
    );
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));
    $job = queuedMailFailureJob(
        (new QueuedTrackedMailWithFailureHook)
            ->to('host-hook@example.test'),
    );

    expect(fn () => $job->failed(null))->not->toThrow(Throwable::class)
        ->and($job->mailable->hostFailureHandled)->toBeTrue()
        ->and(MailNotification::query()->count())->toBe(0);
});
