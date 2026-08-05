<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Enums\FailurePolicy;
use Nvl\MailNotifications\Events\MailTrackingFailed;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\Laravel\Transport\TrackingTransport;
use Nvl\MailNotifications\Support\StagedTracking;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Nvl\MailNotifications\ValueObjects\TransportResult;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Throwable;
use WeakMap;

/**
 * Coordinates transient Mailable state with provider-neutral lifecycle services.
 */
final class TrackingRuntime
{
    /**
     * Tracking state keyed by non-sensitive correlation UUID.
     *
     * @var array<string, StagedTracking>
     */
    private array $staged = [];

    /**
     * Correlation identifiers associated with built Symfony messages.
     *
     * @var WeakMap<Email, string>
     */
    private WeakMap $messages;

    /**
     * Create the in-process tracking runtime.
     */
    public function __construct(
        private readonly TrackingEligibility $eligibility,
        private readonly TrackingLifecycle $lifecycle,
        private readonly ProviderMessageIdRegistry $messageIds,
        private readonly MailTrackingEventDispatcher $events,
        private readonly MessageNormalizer $normalizer,
    ) {
        $this->messages = new WeakMap;
    }

    /**
     * Determine whether an explicitly trackable message should be tracked.
     */
    public function shouldTrack(?string $mailer): bool
    {
        return $this->eligibility->shouldTrack($mailer);
    }

    /**
     * Stage one opted-in message and return its correlation identifier.
     */
    public function stage(
        TrackingContext $context,
        ?string $mailer,
        ?string $queueReference = null,
    ): string {
        $correlationId = (string) Str::uuid();
        $this->staged[$correlationId] = new StagedTracking(
            correlationId: $correlationId,
            mailer: $this->eligibility->resolveMailer($mailer),
            context: $context,
            queueReference: $this->normalizeQueueReference($queueReference),
        );

        return $correlationId;
    }

    /**
     * Reconcile Laravel's terminal queued-Mailable failure.
     */
    public function queuedFailure(
        TrackingContext $context,
        ?string $mailer,
        string $queueReference,
        Email $message,
        Throwable $exception,
    ): void {
        if (! $this->shouldTrack($mailer)) {
            return;
        }

        $normalizedReference = $this->normalizeQueueReference($queueReference);

        if ($normalizedReference === null) {
            throw new MailTrackingException(
                'A queued mail failure requires a valid queue reference.',
            );
        }

        $resolvedMailer = $this->eligibility->resolveMailer($mailer);
        $prepared = $this->normalizer->normalizeQueuedFailure(
            message: $message,
            correlationId: $normalizedReference,
            mailer: $resolvedMailer,
            context: $context,
            queueReference: $normalizedReference,
        );

        $this->lifecycle->queuedFailure($prepared, $exception);
    }

    /**
     * Announce a best-effort queued-failure synchronization error.
     */
    public function queuedFailureSynchronizationFailed(
        string $queueReference,
        Throwable $exception,
    ): void {
        $this->events->dispatch(new MailTrackingFailed(
            correlationId: $queueReference,
            attemptId: null,
            exceptionClass: $exception::class,
        ));
    }

    /**
     * Return transient state for a correlation identifier.
     */
    public function staged(string $correlationId): ?StagedTracking
    {
        return $this->staged[$correlationId] ?? null;
    }

    /**
     * Associate a built message without relying on a removable wire header.
     */
    public function associate(Email $message, string $correlationId): void
    {
        $staged = $this->staged($correlationId);

        if ($staged instanceof StagedTracking) {
            $this->messages[$message] = $correlationId;
            $staged->message = $message;
        }
    }

    /**
     * Determine whether a built message belongs to one staged attempt.
     */
    public function isAssociated(Email $message, string $correlationId): bool
    {
        $associated = $this->messages[$message] ?? null;

        return $this->staged($correlationId) instanceof StagedTracking
            && is_string($associated)
            && hash_equals($associated, $correlationId);
    }

    /**
     * Wrap one concrete transport so final wire state drives tracking.
     */
    public function wrapTransport(
        string $correlationId,
        TransportInterface $transport,
    ): TransportInterface {
        $staged = $this->staged($correlationId);

        if (! $staged instanceof StagedTracking) {
            return $transport;
        }

        $staged->transportWrapped = true;

        return new TrackingTransport(
            transport: $transport,
            runtime: $this,
            correlationId: $correlationId,
            mailer: $staged->mailer,
        );
    }

    /**
     * Handle a mailer whose transport cannot be decorated safely.
     */
    public function unsupportedMailer(): void
    {
        $exception = new MailTrackingException(
            'Tracked delivery requires an Illuminate mailer whose Symfony transport can be decorated.',
        );
        $this->events->dispatch(new MailTrackingFailed(
            correlationId: (string) Str::uuid(),
            attemptId: null,
            exceptionClass: $exception::class,
        ));

        if ($this->eligibility->failurePolicy() === FailurePolicy::FailClosed) {
            throw $exception;
        }
    }

    /**
     * Normalize and store a pre-send snapshot without starting persistence.
     */
    public function prepare(
        string $correlationId,
        Email $message,
        ?string $mailer = null,
    ): void {
        $staged = $this->staged($correlationId);

        if (! $staged instanceof StagedTracking
            || $staged->attempt !== null) {
            return;
        }

        $staged->prepared = null;

        try {
            $staged->prepared = $this->normalizer->normalize(
                message: $message,
                correlationId: $correlationId,
                mailer: $mailer ?? $staged->mailer,
                context: $staged->context,
                queueReference: $staged->queueReference,
            );
            $staged->beginFailed = false;
        } catch (Throwable $exception) {
            $staged->beginFailed = true;
            $this->emitFailure($staged, $exception);

            if ($this->eligibility->failurePolicy() === FailurePolicy::FailClosed) {
                $this->forget($correlationId);

                throw $exception;
            }
        }
    }

    /**
     * Normalize and persist the final message immediately before transport.
     */
    public function beforeTransport(
        string $correlationId,
        Email $message,
    ): void {
        $staged = $this->staged($correlationId);

        if (! $staged instanceof StagedTracking) {
            return;
        }

        $this->prepare($correlationId, $message);
        $staged = $this->staged($correlationId);

        if ($staged instanceof StagedTracking
            && $staged->prepared instanceof PreparedMessage) {
            $this->begin($correlationId, $staged->prepared);
        }
    }

    /**
     * Persist the pending attempt observed immediately before transport.
     */
    public function begin(string $correlationId, PreparedMessage $message): void
    {
        $staged = $this->staged($correlationId);

        if (! $staged instanceof StagedTracking || $staged->attempt !== null) {
            return;
        }

        try {
            $staged->attempt = $this->lifecycle->begin($message);
        } catch (Throwable $exception) {
            $staged->beginFailed = true;
            $this->emitFailure($staged, $exception);

            if ($this->eligibility->failurePolicy() === FailurePolicy::FailClosed) {
                $this->forget($correlationId);

                throw $exception;
            }
        }
    }

    /**
     * Record completed transport acceptance and release transient state.
     */
    public function accepted(string $correlationId, TransportResult $result): void
    {
        $staged = $this->staged($correlationId);

        if (! $staged instanceof StagedTracking) {
            return;
        }

        $messageId = null;

        try {
            if ($staged->attempt !== null) {
                $messageId = $this->messageIds->resolve($result);
                $this->lifecycle->accepted(
                    $staged->attempt,
                    new ProviderAcceptance($messageId),
                );
            }
        } catch (Throwable $exception) {
            $this->emitFailure($staged, $exception, $messageId);
        } finally {
            $this->forget($correlationId);
        }
    }

    /**
     * Record a local delivery failure while preserving the delivery exception.
     */
    public function failed(string $correlationId, Throwable $exception): void
    {
        $staged = $this->staged($correlationId);

        if (! $staged instanceof StagedTracking) {
            return;
        }

        try {
            if ($staged->attempt !== null) {
                $this->lifecycle->failed($staged->attempt, $exception);
            } elseif (! $staged->beginFailed) {
                $this->emitFailure($staged, $exception);
            }
        } catch (Throwable $trackingException) {
            $this->emitFailure($staged, $trackingException);
        } finally {
            $this->forget($correlationId);
        }
    }

    /**
     * Persist and fail a prepared attempt when Laravel cancels before transport.
     */
    public function cancelled(string $correlationId, Throwable $exception): void
    {
        $staged = $this->staged($correlationId);

        if (! $staged instanceof StagedTracking) {
            return;
        }

        if ($staged->prepared === null
            && $staged->message instanceof Email
            && ! $staged->beginFailed) {
            try {
                $this->prepare($correlationId, $staged->message);
            } catch (Throwable) {
                return;
            }

            $staged = $this->staged($correlationId);

            if (! $staged instanceof StagedTracking) {
                return;
            }
        }

        if ($staged->attempt === null
            && $staged->prepared instanceof PreparedMessage
            && ! $staged->beginFailed) {
            try {
                $this->begin($correlationId, $staged->prepared);
            } catch (Throwable) {
                return;
            }
        }

        $this->failed($correlationId, $exception);
    }

    /**
     * Discard transient state after a lifecycle terminal point.
     */
    public function forget(string $correlationId): void
    {
        unset($this->staged[$correlationId]);
    }

    /**
     * Emit a content-free operational tracking failure.
     */
    private function emitFailure(
        StagedTracking $staged,
        Throwable $exception,
        ?ProviderMessageId $messageId = null,
    ): void {
        $this->events->dispatch(new MailTrackingFailed(
            correlationId: $staged->correlationId,
            attemptId: $staged->attempt?->id,
            exceptionClass: $exception::class,
            messageId: $messageId,
        ));
    }

    /**
     * Normalize a non-sensitive queue reference carried through serialization.
     */
    private function normalizeQueueReference(?string $queueReference): ?string
    {
        if ($queueReference === null) {
            return null;
        }

        $normalizedReference = trim($queueReference);

        if (! Str::isUuid($normalizedReference)) {
            throw new MailTrackingException(
                'A mail tracking queue reference must be a valid UUID.',
            );
        }

        return $normalizedReference;
    }
}
