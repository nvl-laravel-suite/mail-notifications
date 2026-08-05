<?php

declare(strict_types=1);

use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Events\MailAcceptedByProvider;
use Nvl\MailNotifications\Events\MailTrackingFailed;
use Nvl\MailNotifications\Events\MailTrackingStarted;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\Laravel\Listeners\TrackMessageBeforeSending;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\MailTestingInterceptor;
use Nvl\MailNotifications\Services\TrackingEligibility;
use Nvl\MailNotifications\Services\TrackingRuntime;
use Nvl\MailNotifications\Support\TrackingHeaders;
use Nvl\MailNotifications\Support\TrackingRuntimeBridge;
use Nvl\MailNotifications\Tests\Fixtures\DecoratedMailer;
use Nvl\MailNotifications\Tests\Fixtures\QueuedTrackedMail;
use Nvl\MailNotifications\Tests\Fixtures\TestTrackable;
use Nvl\MailNotifications\Tests\Fixtures\TrackedMail;
use Nvl\MailNotifications\Tests\Fixtures\UnavailableTrackingLifecycle;
use Nvl\MailNotifications\Tests\Fixtures\UntrackedMail;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\MailNotifications\ValueObjects\TransitionResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Symfony\Component\Mime\Address;

it('tracks an explicitly opted-in mailable through transport acceptance', function () {
    Mail::to('recipient@example.test')
        ->cc('copy@example.test')
        ->bcc('blind@example.test')
        ->send(new TrackedMail(metadata: [
            'request_id' => 'request-123',
            'api_token' => 'secret-value',
            'credentials' => [
                'Authorization' => 'Bearer secret',
                'request_id' => 'nested-request',
            ],
        ]));

    $notification = MailNotification::query()->sole();

    expect($notification)
        ->status->toBe(MailDeliveryStatus::Accepted)
        ->mailer->toBe('array')
        ->provider->toBe('array')
        ->provider_message_id->not->toBeNull()
        ->primary_recipient_email->toBe('recipient@example.test')
        ->and($notification->to_recipients)->toEqual([
            ['email' => 'recipient@example.test', 'name' => null],
        ])
        ->and($notification->cc_recipients)->toEqual([
            ['email' => 'copy@example.test', 'name' => null],
        ])
        ->and($notification->bcc_recipients)->toEqual([
            ['email' => 'blind@example.test', 'name' => null],
        ])
        ->and($notification->metadata)->toMatchArray([
            'request_id' => 'request-123',
            'api_token' => '[REDACTED]',
            'credentials' => [
                'Authorization' => '[REDACTED]',
                'request_id' => 'nested-request',
            ],
        ]);
});

it('supports fluent host context composition', function () {
    config()->set('mail-notifications.notifiable_types', [
        'test-account' => TestTrackable::class,
    ]);
    app()->forgetInstance(MailNotificationNotifiableTypeRegistry::class);
    app()->forgetInstance(TrackingLifecycle::class);
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));

    $mail = (new TrackedMail)
        ->forNotifiable(new TestTrackable('account-123'))
        ->withTrackingMetadata([
            'workflow' => 'replacement-readiness',
        ])
        ->locale('bg');

    Mail::to('recipient@example.test')->send($mail);

    $notification = MailNotification::query()->sole();

    expect($notification->notifiable_type)->toBe('test-account')
        ->and($notification->notifiable_id)->toBe('account-123')
        ->and($notification->metadata)->toMatchArray([
            'workflow' => 'replacement-readiness',
        ])
        ->and($mail->locale)->toBe('bg');
});

it('consumes fluent host context after one successful delivery', function () {
    config()->set('mail-notifications.notifiable_types', [
        'test-account' => TestTrackable::class,
    ]);
    app()->forgetInstance(MailNotificationNotifiableTypeRegistry::class);
    app()->forgetInstance(TrackingLifecycle::class);
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));

    $mail = (new TrackedMail)
        ->forNotifiable(new TestTrackable('one-delivery-account'))
        ->withTrackingMetadata([
            'workflow' => 'one-delivery',
        ])
        ->to('recipient@example.test');

    $mail->send(app(MailFactory::class));
    $first = MailNotification::query()->sole();
    $mail->send(app(MailFactory::class));
    $second = MailNotification::query()
        ->where('id', '!=', $first->id)
        ->sole();

    expect($first)
        ->notifiable_type->toBe('test-account')
        ->notifiable_id->toBe('one-delivery-account')
        ->and($first->metadata)->toHaveKey('workflow', 'one-delivery')
        ->and($second->notifiable_type)->toBeNull()
        ->and($second->notifiable_id)->toBeNull()
        ->and($second->metadata)->not->toHaveKey('workflow');
});

it('consumes fluent host context after a failed delivery attempt', function () {
    config()->set('mail-notifications.notifiable_types', [
        'test-account' => TestTrackable::class,
    ]);
    app()->forgetInstance(MailNotificationNotifiableTypeRegistry::class);
    app()->forgetInstance(TrackingLifecycle::class);
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));
    $failFirstDelivery = true;

    Event::listen(
        MessageSending::class,
        static function () use (&$failFirstDelivery): void {
            if ($failFirstDelivery) {
                $failFirstDelivery = false;

                throw new RuntimeException('First delivery failed.');
            }
        },
    );

    $mail = (new TrackedMail)
        ->forNotifiable(new TestTrackable('failed-delivery-account'))
        ->withTrackingMetadata([
            'workflow' => 'failed-delivery',
        ])
        ->to('recipient@example.test');

    expect(static fn () => $mail->send(app(MailFactory::class)))
        ->toThrow(RuntimeException::class, 'First delivery failed.');

    $failed = MailNotification::query()->sole();
    $mail->send(app(MailFactory::class));
    $accepted = MailNotification::query()
        ->where('id', '!=', $failed->id)
        ->sole();

    expect($failed)
        ->status->toBe(MailDeliveryStatus::Failed)
        ->notifiable_type->toBe('test-account')
        ->notifiable_id->toBe('failed-delivery-account')
        ->and($failed->metadata)->toHaveKey('workflow', 'failed-delivery')
        ->and($accepted->status)->toBe(MailDeliveryStatus::Accepted)
        ->and($accepted->notifiable_type)->toBeNull()
        ->and($accepted->notifiable_id)->toBeNull()
        ->and($accepted->metadata)->not->toHaveKey('workflow');
});

it('serializes fluent host context for queued delivery', function () {
    config()->set('mail-notifications.notifiable_types', [
        'test-account' => TestTrackable::class,
    ]);
    app()->forgetInstance(MailNotificationNotifiableTypeRegistry::class);
    app()->forgetInstance(TrackingLifecycle::class);
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));

    $serialized = serialize(
        (new TrackedMail)
            ->forNotifiable(new TestTrackable('queued-account'))
            ->withTrackingMetadata([
                'dispatch' => 'queued',
            ]),
    );
    $mail = unserialize($serialized);

    expect($mail)->toBeInstanceOf(TrackedMail::class);

    Mail::to('recipient@example.test')->send($mail);

    expect(MailNotification::query()->sole())
        ->notifiable_type->toBe('test-account')
        ->notifiable_id->toBe('queued-account')
        ->and(MailNotification::query()->sole()->metadata)
        ->toMatchArray([
            'dispatch' => 'queued',
        ]);
});

it('leaves ordinary untracked mail untouched', function () {
    Mail::to('recipient@example.test')->send(new UntrackedMail);

    expect(MailNotification::query()->count())->toBe(0);
});

it('supports a per-message tracking opt-out', function () {
    Mail::to('recipient@example.test')->send(
        (new TrackedMail)->withoutMailTracking(),
    );

    expect(MailNotification::query()->count())->toBe(0);
});

it('supports a per-message opt-out followed by opt-in', function () {
    $mail = (new TrackedMail)
        ->withoutMailTracking()
        ->withMailTracking();

    Mail::to('recipient@example.test')->send($mail);

    expect(MailNotification::query()->count())->toBe(1);
});

it('excludes configured Laravel mailers without changing delivery', function () {
    config()->set('mail-notifications.tracking.excluded_mailers', ['array']);

    Mail::to('recipient@example.test')->send(new TrackedMail);

    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();

    expect(MailNotification::query()->count())->toBe(0)
        ->and($transport->messages())->toHaveCount(1);
});

it('does not resolve tracking context for an excluded mailer', function () {
    config()->set('mail-notifications.tracking.excluded_mailers', ['array']);

    Mail::to('recipient@example.test')->send(
        new TrackedMail(throwOnTrackingContext: true),
    );

    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();

    expect(MailNotification::query()->count())->toBe(0)
        ->and($transport->messages())->toHaveCount(1);
});

it('uses the actual explicitly selected mailer for tracking exclusions', function () {
    config()->set('mail-notifications.tracking.excluded_mailers', ['smtp-test']);

    Mail::mailer('smtp-test')
        ->to('recipient@example.test')
        ->send(new TrackedMail);

    $transport = app(MailManager::class)
        ->mailer('smtp-test')
        ->getSymfonyTransport();

    expect(MailNotification::query()->count())->toBe(0)
        ->and($transport->messages())->toHaveCount(1);
});

it('normalizes configured mailer exclusions', function () {
    config()->set('mail-notifications.tracking.excluded_mailers', [
        ' smtp-test ',
        '',
        'smtp-test',
    ]);

    Mail::mailer('smtp-test')
        ->to('recipient@example.test')
        ->send(new TrackedMail);

    expect(app(TrackingEligibility::class)->excludedMailers())
        ->toBe(['smtp-test'])
        ->and(MailNotification::query()->count())->toBe(0);
});

it('tracks an explicitly selected mailer when only the default mailer is excluded', function () {
    config()->set('mail-notifications.tracking.excluded_mailers', ['array']);

    Mail::mailer('smtp-test')
        ->to('recipient@example.test')
        ->send(new TrackedMail);

    $notification = MailNotification::query()->sole();

    expect($notification)
        ->mailer->toBe('smtp-test')
        ->provider->toBe('smtp-test');
});

it('uses the configured provider alias for a Laravel mailer', function () {
    config()->set(
        'mail-notifications.providers.mailers.smtp-test',
        'transactional-smtp',
    );

    Mail::mailer('smtp-test')
        ->to('recipient@example.test')
        ->send(new TrackedMail);

    expect(MailNotification::query()->sole()->provider)
        ->toBe('transactional-smtp');
});

it('does not persist a subject when subject storage is disabled', function () {
    config()->set('mail-notifications.tracking.store_subject', false);

    Mail::to('recipient@example.test')->send(new TrackedMail);

    expect(MailNotification::query()->sole()->subject)->toBeNull();
});

it('rejects malformed subject-storage configuration', function () {
    config()->set('mail-notifications.tracking.store_subject', 'false');

    expect(fn () => Mail::to('recipient@example.test')->send(new TrackedMail))
        ->toThrow(InvalidArgumentException::class, 'configured with a boolean');
});

it('persists standards-compliant subjects longer than a database varchar', function () {
    $subject = str_repeat('Long subject segment ', 20);

    Mail::to('recipient@example.test')->send(
        new TrackedMail(subject: $subject),
    );

    expect(MailNotification::query()->sole()->subject)->toBe($subject);
});

it('disables tracking globally without disabling Laravel Mail', function () {
    config()->set('mail-notifications.enabled', false);

    Mail::to('recipient@example.test')->send(new TrackedMail);

    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();

    expect(MailNotification::query()->count())->toBe(0)
        ->and($transport->messages())->toHaveCount(1);
});

it('disables tracking independently without disabling presentation or Laravel Mail', function () {
    config()->set('mail-notifications.tracking.enabled', false);

    Mail::to('recipient@example.test')->send(new TrackedMail);

    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();

    expect(MailNotification::query()->count())->toBe(0)
        ->and(config('mail-notifications.presentation.enabled'))->toBeTrue()
        ->and($transport->messages())->toHaveCount(1);
});

it('rejects malformed tracking feature switches', function (string $key) {
    config()->set($key, 'false');

    expect(fn () => app(TrackingEligibility::class)->enabled())
        ->toThrow(MailTrackingException::class, 'must be a boolean');
})->with([
    'package switch' => 'mail-notifications.enabled',
    'tracking switch' => 'mail-notifications.tracking.enabled',
]);

it('continues delivery when fail-open tracking persistence is unavailable', function () {
    config()->set('mail-notifications.tracking.failure_policy', 'fail_open');
    app()->instance(TrackingLifecycle::class, new UnavailableTrackingLifecycle);
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));

    Mail::to('recipient@example.test')->send(new TrackedMail);

    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();

    expect($transport->messages())->toHaveCount(1);
});

it('continues fail-open delivery when an operational failure listener throws', function () {
    config()->set('mail-notifications.tracking.failure_policy', 'fail_open');
    app()->instance(TrackingLifecycle::class, new UnavailableTrackingLifecycle);
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));
    Event::listen(
        MailTrackingFailed::class,
        static fn (): never => throw new RuntimeException('Host listener failed.'),
    );

    Mail::to('recipient@example.test')->send(new TrackedMail);

    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();

    expect($transport->messages())->toHaveCount(1);
});

it('exposes provider acceptance identity for safe tracking repair without resending', function () {
    $database = app(DatabaseTrackingLifecycle::class);
    $lifecycle = new class($database) implements TrackingLifecycle
    {
        public function __construct(
            private readonly DatabaseTrackingLifecycle $database,
        ) {}

        public function begin(PreparedMessage $message): TrackingAttempt
        {
            return $this->database->begin($message);
        }

        public function accepted(
            TrackingAttempt $attempt,
            ProviderAcceptance $acceptance,
        ): void {
            throw new RuntimeException('Acceptance persistence is unavailable.');
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
    $failure = null;

    app()->instance(TrackingLifecycle::class, $lifecycle);
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));
    Event::listen(
        MailTrackingFailed::class,
        static function (MailTrackingFailed $event) use (&$failure): void {
            $failure = $event;
        },
    );

    $sent = Mail::to('recipient@example.test')->send(new TrackedMail);
    $notification = MailNotification::query()->sole();
    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();
    $wireMessageId = $transport->messages()
        ->sole()
        ->getMessageId();

    expect($sent)->not->toBeNull()
        ->and($transport->messages())->toHaveCount(1)
        ->and($notification->status)->toBe(MailDeliveryStatus::Pending)
        ->and($failure)->toBeInstanceOf(MailTrackingFailed::class)
        ->and($failure->correlationId)->toBe($notification->correlation_id)
        ->and($failure->attemptId)->toBe($notification->id)
        ->and($failure->exceptionClass)->toBe(RuntimeException::class)
        ->and($failure->messageId?->provider)->toBe('array')
        ->and($failure->messageId?->value)->toBe($wireMessageId);

    $attempt = new TrackingAttempt(
        id: $failure->attemptId,
        correlationId: $failure->correlationId,
    );
    $acceptance = new ProviderAcceptance($failure->messageId);

    $database->accepted($attempt, $acceptance);
    $database->accepted($attempt, $acceptance);

    expect($notification->fresh())
        ->status->toBe(MailDeliveryStatus::Accepted)
        ->provider->toBe('array')
        ->provider_message_id->toBe($wireMessageId)
        ->and($transport->messages())->toHaveCount(1);
});

it('keeps tracking event listeners observational during delivery', function () {
    Event::listen(
        MailTrackingStarted::class,
        static fn (): never => throw new RuntimeException('Host listener failed.'),
    );

    Mail::to('recipient@example.test')->send(new TrackedMail);

    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();

    expect($transport->messages())->toHaveCount(1)
        ->and(MailNotification::query()->sole()->status)
        ->toBe(MailDeliveryStatus::Accepted);
});

it('dispatches lifecycle event objects through standard Laravel semantics', function () {
    Event::fake([
        MailTrackingStarted::class,
        MailAcceptedByProvider::class,
    ]);

    Mail::to('recipient@example.test')->send(new TrackedMail);

    Event::assertDispatched(
        MailTrackingStarted::class,
        static fn (MailTrackingStarted $event): bool => $event->category === 'test.message',
    );
    Event::assertDispatched(
        MailAcceptedByProvider::class,
        static fn (MailAcceptedByProvider $event): bool => $event->attempt->id
            === MailNotification::query()->sole()->id,
    );
});

it('blocks delivery when fail-closed tracking persistence is unavailable', function () {
    config()->set('mail-notifications.tracking.failure_policy', 'fail_closed');
    app()->instance(TrackingLifecycle::class, new UnavailableTrackingLifecycle);
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));

    expect(
        fn () => Mail::to('recipient@example.test')->send(new TrackedMail),
    )->toThrow(RuntimeException::class, 'Tracking storage is unavailable.');

    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();

    expect($transport->messages())->toHaveCount(0);
});

it('marks a tracked attempt failed when another listener cancels delivery', function () {
    Event::listen(MessageSending::class, static fn (): false => false);

    Mail::to('recipient@example.test')->send(new TrackedMail);

    $notification = MailNotification::query()->sole();
    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();

    expect($notification)
        ->status->toBe(MailDeliveryStatus::Failed)
        ->provider->toBeNull()
        ->provider_message_id->toBeNull()
        ->and($notification->metadata)->toHaveKey('failure.exception')
        ->and($transport->messages())->toHaveCount(0);
});

it('tracks cancellation even when the cancelling listener runs first', function () {
    Event::forget(MessageSending::class);
    Event::listen(MessageSending::class, static fn (): false => false);
    $trackingListener = app(TrackMessageBeforeSending::class);
    Event::listen(
        MessageSending::class,
        static function (MessageSending $event) use ($trackingListener): void {
            $trackingListener->handle($event);
        },
    );

    Mail::to('recipient@example.test')->send(new TrackedMail);

    $notification = MailNotification::query()->sole();
    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();

    expect($notification)
        ->status->toBe(MailDeliveryStatus::Failed)
        ->provider->toBeNull()
        ->provider_message_id->toBeNull()
        ->and($transport->messages())->toHaveCount(0);
});

it('rejects custom mailer contracts before fail-closed tracked delivery', function () {
    Event::fake([MailTrackingFailed::class]);
    $laravelMailer = app(MailManager::class)->mailer('array');
    $decoratedMailer = new DecoratedMailer($laravelMailer);
    $mail = (new TrackedMail)->to('recipient@example.test');

    expect(fn () => $mail->send($decoratedMailer))
        ->toThrow(
            MailTrackingException::class,
            'requires an Illuminate mailer',
        )
        ->and(MailNotification::query()->count())->toBe(0)
        ->and($laravelMailer->getSymfonyTransport()->messages())->toHaveCount(0);

    Event::assertDispatched(
        MailTrackingFailed::class,
        static fn (MailTrackingFailed $event): bool => $event->attemptId === null
            && $event->exceptionClass === MailTrackingException::class,
    );
    Event::assertDispatchedTimes(MailTrackingFailed::class, 1);
});

it('delivers custom mailer contracts untracked under fail-open policy', function () {
    Event::fake([MailTrackingFailed::class]);
    config()->set('mail-notifications.tracking.failure_policy', 'fail_open');
    $laravelMailer = app(MailManager::class)->mailer('array');
    $decoratedMailer = new DecoratedMailer($laravelMailer);

    $sent = (new TrackedMail)
        ->to('recipient@example.test')
        ->send($decoratedMailer);

    expect($sent)
        ->not->toBeNull()
        ->and(MailNotification::query()->count())->toBe(0)
        ->and($laravelMailer->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(
            $laravelMailer->getSymfonyTransport()
                ->messages()
                ->sole()
                ->getOriginalMessage()
                ->getHeaders()
                ->has(TrackingHeaders::CORRELATION),
        )->toBeFalse();

    Event::assertDispatchedTimes(MailTrackingFailed::class, 1);
});

it('resolves a mail factory once and sends through that exact mailer', function () {
    Event::fake([MailTrackingFailed::class]);
    config()->set('mail-notifications.tracking.failure_policy', 'fail_open');
    $laravelMailer = app(MailManager::class)->mailer('array');
    $factory = new class(new DecoratedMailer($laravelMailer)) implements MailFactory
    {
        public int $resolutions = 0;

        public function __construct(
            private readonly MailerContract $mailer,
        ) {}

        public function mailer($name = null): MailerContract
        {
            $this->resolutions++;

            return $this->mailer;
        }
    };

    (new TrackedMail)
        ->to('recipient@example.test')
        ->send($factory);

    expect($factory->resolutions)->toBe(1)
        ->and($laravelMailer->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(MailNotification::query()->count())->toBe(0);

    Event::assertDispatchedTimes(MailTrackingFailed::class, 1);
});

it('leaves excluded custom mailer contracts completely untouched', function () {
    Event::fake([MailTrackingFailed::class]);
    config()->set(
        'mail-notifications.tracking.excluded_mailers',
        ['decorated'],
    );
    $laravelMailer = app(MailManager::class)->mailer('array');
    $decoratedMailer = new DecoratedMailer($laravelMailer);

    (new TrackedMail(throwOnTrackingContext: true))
        ->mailer('decorated')
        ->to('recipient@example.test')
        ->send($decoratedMailer);

    expect($laravelMailer->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(MailNotification::query()->count())->toBe(0);

    Event::assertNotDispatched(MailTrackingFailed::class);
});

it('reuses a Mailable after fail-open custom mailer delivery', function () {
    Event::fake([MailTrackingFailed::class]);
    config()->set('mail-notifications.tracking.failure_policy', 'fail_open');
    $laravelMailer = app(MailManager::class)->mailer('array');
    $mail = (new TrackedMail)->to('recipient@example.test');

    $mail->send(new DecoratedMailer($laravelMailer));

    config()->set('mail-notifications.tracking.failure_policy', 'fail_closed');
    $mail->send($laravelMailer);

    expect($laravelMailer->getSymfonyTransport()->messages())->toHaveCount(2)
        ->and(MailNotification::query()->sole()->status)
        ->toBe(MailDeliveryStatus::Accepted);

    Event::assertDispatchedTimes(MailTrackingFailed::class, 1);
});

it('persists the final recipients after later host listeners mutate the message', function () {
    Event::listen(MessageSending::class, static function (MessageSending $event): void {
        $event->message->to(new Address('final@example.test', 'Final Recipient'));
        $event->message->cc(new Address('final-copy@example.test'));
        $event->message->bcc(new Address('final-blind@example.test'));
        $event->message->getHeaders()->remove(TrackingHeaders::CORRELATION);
    });

    Mail::to('initial@example.test')
        ->cc('initial-copy@example.test')
        ->send(new TrackedMail);

    $notification = MailNotification::query()->sole();
    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();
    $wireMessage = $transport->messages()->sole()->getOriginalMessage();

    expect($notification)
        ->status->toBe(MailDeliveryStatus::Accepted)
        ->to_recipients->toEqual([
            ['email' => 'final@example.test', 'name' => 'Final Recipient'],
        ])
        ->cc_recipients->toEqual([
            ['email' => 'final-copy@example.test', 'name' => null],
        ])
        ->bcc_recipients->toEqual([
            ['email' => 'final-blind@example.test', 'name' => null],
        ])
        ->and($wireMessage->getHeaders()->has(TrackingHeaders::CORRELATION))
        ->toBeTrue();
});

it('does not let a non-cancelling listener short-circuit fail-closed tracking', function () {
    Event::listen(MessageSending::class, static fn (): true => true);

    Mail::to('recipient@example.test')->send(new TrackedMail);

    expect(MailNotification::query()->sole()->status)
        ->toBe(MailDeliveryStatus::Accepted);
});

it('records a prepared attempt failed when a sending listener throws', function () {
    Event::listen(
        MessageSending::class,
        static fn (): never => throw new RuntimeException('Host sending listener failed.'),
    );

    expect(fn () => Mail::to('recipient@example.test')->send(new TrackedMail))
        ->toThrow(RuntimeException::class, 'Host sending listener failed.');

    $transport = app(MailManager::class)
        ->mailer('array')
        ->getSymfonyTransport();

    expect(MailNotification::query()->sole()->status)
        ->toBe(MailDeliveryStatus::Failed)
        ->and($transport->messages())->toHaveCount(0);
});

it('does not mark accepted mail failed when a sent listener throws', function () {
    Event::listen(
        MessageSent::class,
        static fn (): never => throw new RuntimeException('Host sent listener failed.'),
    );

    expect(fn () => Mail::to('recipient@example.test')->send(new TrackedMail))
        ->toThrow(RuntimeException::class, 'Host sent listener failed.');

    expect(MailNotification::query()->sole())
        ->status->toBe(MailDeliveryStatus::Accepted)
        ->failed_at->toBeNull()
        ->and(MailNotification::query()->sole()->metadata)
        ->not->toHaveKey('failure');
});

it('tracks the effective Laravel test recipient and removes copy recipients', function () {
    config()->set('mail.testing', [
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'to_name' => 'Preview Inbox',
        'respect_environment' => true,
        'environments' => ['testing'],
    ]);
    app(MailTestingInterceptor::class)->apply();

    Mail::to('real@example.test')
        ->cc('copy@example.test')
        ->bcc('blind@example.test')
        ->send(new TrackedMail);

    $notification = MailNotification::query()->sole();

    expect($notification->to_recipients)->toEqual([
        ['email' => 'preview@example.test', 'name' => 'Preview Inbox'],
    ])->and($notification->cc_recipients)->toBe([])
        ->and($notification->bcc_recipients)->toBe([]);
});

it('tracks valid internationalized recipients without changing their identity', function () {
    Mail::to('用户@example.com')->send(new TrackedMail);

    expect(MailNotification::query()->sole()->to_recipients)->toEqual([
        ['email' => '用户@example.com', 'name' => null],
    ]);
});

it('applies Laravel test-recipient interception to explicitly selected mailers', function () {
    config()->set('mail.testing', [
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'to_name' => 'Preview Inbox',
        'respect_environment' => true,
        'environments' => ['testing'],
    ]);
    app(MailTestingInterceptor::class)->apply();

    Mail::mailer('smtp-test')
        ->to('real@example.test')
        ->send(new TrackedMail);

    $notification = MailNotification::query()->sole();

    expect($notification)
        ->mailer->toBe('smtp-test')
        ->and($notification->to_recipients)->toEqual([
            ['email' => 'preview@example.test', 'name' => 'Preview Inbox'],
        ]);
});

it('does not apply testing interception outside configured environments', function () {
    config()->set('mail.testing', [
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => true,
        'environments' => ['local'],
    ]);
    app(MailTestingInterceptor::class)->apply();

    Mail::to('real@example.test')->send(new TrackedMail);

    expect(MailNotification::query()->sole()->primary_recipient_email)
        ->toBe('real@example.test');
});

it('fails safe when testing interception has no environment allowlist', function () {
    config()->set('mail.testing', [
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => true,
        'environments' => [],
    ]);
    app(MailTestingInterceptor::class)->apply();

    Mail::to('real@example.test')->send(new TrackedMail);

    expect(MailNotification::query()->sole()->primary_recipient_email)
        ->toBe('real@example.test');
});

it('rejects an enabled testing mode without a safe recipient', function () {
    config()->set('mail.testing', [
        'enabled' => true,
        'to_address' => 'not-an-email',
        'respect_environment' => false,
    ]);

    expect(fn () => app(MailTestingInterceptor::class)->apply())
        ->toThrow(MailTrackingException::class);
});

it('allows an internationalized testing recipient accepted by Laravel Mail', function () {
    config()->set('mail.testing', [
        'enabled' => true,
        'to_address' => '用户@example.com',
        'to_name' => ' Preview Inbox ',
        'respect_environment' => false,
    ]);

    app(MailTestingInterceptor::class)->apply();
    Mail::to('real@example.test')->send(new TrackedMail);

    expect(MailNotification::query()->sole()->to_recipients)->toEqual([
        ['email' => '用户@example.com', 'name' => 'Preview Inbox'],
    ]);
});

it('prefers an explicit host testing configuration over package fallbacks', function () {
    config()->set('mail.testing', [
        'enabled' => false,
    ]);
    config()->set('mail-notifications.testing', [
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => false,
    ]);
    app(MailTestingInterceptor::class)->apply();

    Mail::to('real@example.test')->send(new TrackedMail);

    expect(MailNotification::query()->sole()->primary_recipient_email)
        ->toBe('real@example.test');
});

it('rejects malformed testing interception switches', function (string $key) {
    config()->set('mail.testing', [
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => true,
        'environments' => ['testing'],
        $key => 'false',
    ]);

    expect(fn () => app(MailTestingInterceptor::class)->apply())
        ->toThrow(MailTrackingException::class, 'must be a boolean');
})->with([
    'enabled switch' => 'enabled',
    'environment switch' => 'respect_environment',
]);

it('can be serialized before a queued send without serializing callbacks', function () {
    $serialized = serialize(new TrackedMail);
    $mail = unserialize($serialized);

    expect($mail)->toBeInstanceOf(TrackedMail::class)
        ->and($mail->hasMailTrackingEnabled())->toBeTrue();

    Mail::to('recipient@example.test')->send($mail);

    expect(MailNotification::query()->count())->toBe(1);
});

it('can be rendered for preview and then serialized before delivery', function () {
    $mail = new TrackedMail;

    expect($mail->render())->toContain('Tracked delivery');

    $serialized = serialize($mail);
    $restored = unserialize($serialized);

    expect($restored)->toBeInstanceOf(TrackedMail::class);

    Mail::to('recipient@example.test')->send($restored);

    expect(MailNotification::query()->count())->toBe(1);
});

it('remains serializable and reusable after a tracked delivery', function () {
    $mail = new TrackedMail;

    Mail::to('first@example.test')->send($mail);

    $restored = unserialize(serialize($mail));

    expect($restored)->toBeInstanceOf(TrackedMail::class);

    Mail::send($restored);

    expect(MailNotification::query()->count())->toBe(2);
});

it('tracks a Mailable after real database queue serialization', function () {
    config()->set('mail-notifications.notifiable_types', [
        'test-account' => TestTrackable::class,
    ]);
    app()->forgetInstance(MailNotificationNotifiableTypeRegistry::class);
    app()->forgetInstance(TrackingLifecycle::class);
    app()->forgetInstance(TrackingRuntime::class);
    TrackingRuntimeBridge::use(app(TrackingRuntime::class));
    config()->set('queue.default', 'mail-notifications-database');
    config()->set('queue.connections.mail-notifications-database', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'mail_notification_test_jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);
    Schema::create('mail_notification_test_jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Mail::to('queued@example.test')->queue(
        (new QueuedTrackedMail)
            ->forNotifiable(new TestTrackable('queued-account'))
            ->withTrackingMetadata([
                'dispatch' => 'database-queue',
            ]),
    );

    $queue = app(QueueManager::class)->connection('mail-notifications-database');
    $job = $queue->pop('default');

    expect($job)->not->toBeNull()
        ->and(MailNotification::query()->count())->toBe(0);

    $job?->fire();

    $notification = MailNotification::query()->sole();

    expect($notification)
        ->status->toBe(MailDeliveryStatus::Accepted)
        ->message_category->toBe('test.queued-message')
        ->primary_recipient_email->toBe('queued@example.test')
        ->notifiable_type->toBe('test-account')
        ->notifiable_id->toBe('queued-account')
        ->and($notification->metadata)
        ->toHaveKey('dispatch', 'database-queue');
});
