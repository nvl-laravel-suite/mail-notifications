<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\SensitiveDataRedactor;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Events\MailAcceptedByProvider;
use Nvl\MailNotifications\Events\MailDeliveryStatusChanged;
use Nvl\MailNotifications\Events\MailTrackingFailed;
use Nvl\MailNotifications\Events\MailTrackingStarted;
use Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException;
use Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\MailNotifications\ValueObjects\TransitionResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Throwable;

/**
 * Owns provider-neutral mail tracking transactions and lifecycle transitions.
 *
 * Transaction ownership is intentional because this contract is the reusable
 * write boundary used by mail listeners and host webhook entry points.
 */
final readonly class DatabaseTrackingLifecycle implements TrackingLifecycle
{
    /**
     * Retry the complete write boundary after transient database contention.
     */
    private const int CONCURRENCY_RETRY_ATTEMPTS = 3;

    /**
     * Create the database lifecycle.
     */
    public function __construct(
        private SensitiveDataRedactor $redactor,
        private MailTrackingEventDispatcher $events,
        private MailNotificationNotifiableTypeRegistry $notifiableTypes,
        private SensitiveStorageCodec $sensitiveStorage,
    ) {}

    /**
     * Begin tracking before a prepared message reaches its transport.
     */
    public function begin(PreparedMessage $message): TrackingAttempt
    {
        $this->assertNotifiableTypeIsRegistered($message);
        $model = new MailNotification;

        return $model->getConnection()->transaction(function () use ($message): TrackingAttempt {
            $now = CarbonImmutable::now('UTC');
            $notification = MailNotification::query()->create(
                $this->notificationAttributes($message, $now),
            );
            $attempt = $this->attempt($notification);

            $this->events->dispatch(new MailTrackingStarted(
                attempt: $attempt,
                category: $notification->message_category,
            ));

            return $attempt;
        });
    }

    /**
     * Record successful acceptance by the configured transport or provider.
     */
    public function accepted(TrackingAttempt $attempt, ProviderAcceptance $acceptance): void
    {
        $model = new MailNotification;

        $model->getConnection()->transaction(function () use ($attempt, $acceptance): void {
            $notification = MailNotification::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();
            $previousStatus = $notification->status;
            $statusChanged = $previousStatus->canTransitionTo(MailDeliveryStatus::Accepted);
            $acceptanceRecorded = $notification->accepted_at !== null;
            $now = CarbonImmutable::now('UTC');

            $this->assertAcceptanceIdentityMatches($notification, $acceptance);

            $notification->provider ??= $acceptance->messageId->provider;
            $notification->provider_message_id ??= $acceptance->messageId->value;
            $notification->accepted_at ??= $now;

            if ($notification->redacted_at === null) {
                $metadata = $notification->metadata ?? [];
                $metadata['transport'] = $this->redactor->redact(
                    $acceptance->metadata,
                );
                $notification->metadata = $metadata;
            }

            if ($statusChanged) {
                $notification->status = MailDeliveryStatus::Accepted;
                $notification->status_changed_at = $now;
            }

            $notification->save();

            if (! $acceptanceRecorded) {
                $this->events->dispatch(new MailAcceptedByProvider(
                    attempt: $attempt,
                    messageId: $acceptance->messageId,
                ));
            }

            if ($statusChanged) {
                $this->events->dispatch(new MailDeliveryStatusChanged(
                    notificationId: $notification->id,
                    previousStatus: $previousStatus,
                    currentStatus: MailDeliveryStatus::Accepted,
                ));
            }
        });
    }

    /**
     * Record a local delivery failure without replacing the original exception.
     */
    public function failed(TrackingAttempt $attempt, Throwable $exception): void
    {
        $model = new MailNotification;

        $model->getConnection()->transaction(function () use ($attempt, $exception): void {
            $notification = MailNotification::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->first();

            if (! $notification instanceof MailNotification) {
                return;
            }

            $this->failNotification($notification, $exception);
        });
    }

    /**
     * Reconcile Laravel's terminal queued-Mailable failure idempotently.
     */
    public function queuedFailure(
        PreparedMessage $message,
        Throwable $exception,
    ): void {
        $queueReference = $message->queueReference;

        if ($queueReference === null || ! Str::isUuid($queueReference)) {
            throw new DomainException(
                'A queued mail failure requires a valid queue reference.',
            );
        }

        $model = new MailNotification;
        $connection = $model->getConnection();

        try {
            $connection->transaction(
                function () use (
                    $exception,
                    $message,
                    $queueReference,
                ): void {
                    $this->recordQueuedFailure(
                        message: $message,
                        exception: $exception,
                        queueReference: $queueReference,
                    );
                },
                self::CONCURRENCY_RETRY_ATTEMPTS,
            );
        } catch (UniqueConstraintViolationException $conflict) {
            $reconciled = $connection->transaction(
                fn (): bool => $this->reconcileQueuedFailureConflict(
                    queueReference: $queueReference,
                    exception: $exception,
                ),
                self::CONCURRENCY_RETRY_ATTEMPTS,
            );

            if (! $reconciled) {
                throw $conflict;
            }
        }
    }

    /**
     * Apply one verified provider delivery event idempotently.
     *
     * @throws AmbiguousDeliveryEventException When identity matches multiple deliveries.
     * @throws UnmatchedDeliveryEventException When identity matches no delivery.
     */
    public function apply(VerifiedDeliveryEvent $event): TransitionResult
    {
        $model = new MailNotification;

        return $model->getConnection()->transaction(
            fn (): TransitionResult => $this->applyWithinTransaction($event),
        );
    }

    /**
     * Apply one verified provider event inside the lifecycle transaction.
     */
    private function applyWithinTransaction(VerifiedDeliveryEvent $event): TransitionResult
    {
        $notification = $this->notificationForEvent($event);
        $previousStatus = $notification->status;
        $eventTable = (new MailNotificationEvent)->getTable();
        $now = CarbonImmutable::now('UTC');
        $metadata = $this->redactor->redact($event->metadata);
        $notificationWasRedacted = $notification->redacted_at !== null;
        $eventValues = [
            'id' => (string) Str::uuid(),
            'mail_notification_id' => $notification->id,
            'provider' => $event->provider,
            'provider_event_id' => $event->eventId,
            'provider_message_id' => $event->providerMessageId,
            'normalized_type' => $event->status->value,
            'occurred_at' => DatabaseTimestamp::format($event->occurredAt),
            'metadata' => $notificationWasRedacted
                ? null
                : $this->sensitiveStorage->encodeArray(
                    'provider_event.metadata',
                    $metadata,
                ),
            'processed_at' => DatabaseTimestamp::format($now),
            'redacted_at' => $notificationWasRedacted
                ? DatabaseTimestamp::format($now)
                : null,
            'created_at' => DatabaseTimestamp::format($now),
            'updated_at' => DatabaseTimestamp::format($now),
        ];

        try {
            $notification->getConnection()->transaction(
                static fn (): bool => $notification->getConnection()
                    ->table($eventTable)
                    ->insert($eventValues),
            );
        } catch (UniqueConstraintViolationException) {
            $this->assertDuplicateEventMatches(
                notification: $notification,
                event: $event,
                metadata: $metadata,
            );

            return new TransitionResult(
                notificationId: $notification->id,
                previousStatus: $previousStatus,
                currentStatus: $previousStatus,
                applied: false,
                duplicate: true,
            );
        }

        $isStale = $notification->provider_occurred_at instanceof CarbonImmutable
            && $event->occurredAt->lessThan($notification->provider_occurred_at);
        $this->backfillMilestoneTimestamp($notification, $event, $previousStatus);

        if ($isStale || ! $previousStatus->canTransitionTo($event->status)) {
            return new TransitionResult(
                notificationId: $notification->id,
                previousStatus: $previousStatus,
                currentStatus: $previousStatus,
                applied: false,
            );
        }

        $updates = [
            'provider' => $event->provider,
            'status' => $event->status,
            'status_changed_at' => $now,
            'provider_occurred_at' => $event->occurredAt,
        ];

        if ($event->providerMessageId !== null) {
            $updates['provider_message_id'] = $event->providerMessageId;
        }

        if ($event->status === MailDeliveryStatus::Accepted) {
            $updates['accepted_at'] = $event->occurredAt;
        }

        if ($event->status === MailDeliveryStatus::Delivered) {
            $updates['delivered_at'] = $event->occurredAt;
        }

        if ($event->status->isTerminal()) {
            $updates['failed_at'] = $event->occurredAt;
        }

        $notification->update($updates);
        $this->events->dispatch(new MailDeliveryStatusChanged(
            notificationId: $notification->id,
            previousStatus: $previousStatus,
            currentStatus: $event->status,
        ));

        return new TransitionResult(
            notificationId: $notification->id,
            previousStatus: $previousStatus,
            currentStatus: $event->status,
            applied: true,
        );
    }

    /**
     * Preserve known milestone times when a later event cannot move status backward.
     */
    private function backfillMilestoneTimestamp(
        MailNotification $notification,
        VerifiedDeliveryEvent $event,
        MailDeliveryStatus $currentStatus,
    ): void {
        if ($event->status === MailDeliveryStatus::Accepted
            && $currentStatus !== MailDeliveryStatus::Pending
            && $notification->accepted_at === null) {
            $notification->accepted_at = $event->occurredAt;
            $notification->save();

            return;
        }

        $deliveryImplied = in_array($currentStatus, [
            MailDeliveryStatus::Delivered,
            MailDeliveryStatus::Opened,
            MailDeliveryStatus::Clicked,
            MailDeliveryStatus::Complained,
            MailDeliveryStatus::Unsubscribed,
        ], true);

        if ($event->status === MailDeliveryStatus::Delivered
            && $deliveryImplied
            && $notification->delivered_at === null) {
            $notification->delivered_at = $event->occurredAt;
            $notification->save();
        }
    }

    /**
     * Ensure a provider event identifier is only reused for the same immutable event.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function assertDuplicateEventMatches(
        MailNotification $notification,
        VerifiedDeliveryEvent $event,
        array $metadata,
    ): void {
        $existingEvent = MailNotificationEvent::query()
            ->where('provider', $event->provider)
            ->where('provider_event_id', $event->eventId)
            ->first();

        if (! $existingEvent instanceof MailNotificationEvent) {
            throw new DomainException(
                'The provider event could not be persisted safely.',
            );
        }

        $sameNotification = hash_equals(
            $existingEvent->mail_notification_id,
            $notification->id,
        );
        $sameProvider = hash_equals($existingEvent->provider, $event->provider);
        $sameEventId = hash_equals(
            $existingEvent->provider_event_id,
            $event->eventId,
        );
        $sameMessage = $existingEvent->provider_message_id === $event->providerMessageId;
        $sameStatus = $existingEvent->normalized_type === $event->status;
        $sameOccurredAt = $existingEvent->occurred_at->format('U.u')
            === $event->occurredAt->format('U.u');
        $metadataWasRedacted = $existingEvent->redacted_at !== null
            && $existingEvent->metadata === null;
        $sameMetadata = $metadataWasRedacted
            || $this->canonicalizeMetadata(
                $existingEvent->metadata ?? [],
            ) === $this->canonicalizeMetadata($metadata);

        if (! $sameNotification
            || ! $sameProvider
            || ! $sameEventId
            || ! $sameMessage
            || ! $sameStatus
            || ! $sameOccurredAt
            || ! $sameMetadata) {
            throw new DomainException(
                'The provider event identifier conflicts with a previously processed event.',
            );
        }
    }

    /**
     * Sort associative metadata recursively while preserving list order.
     *
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private function canonicalizeMetadata(array $metadata): array
    {
        if (! array_is_list($metadata)) {
            ksort($metadata, SORT_STRING);
        }

        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $metadata[$key] = $this->canonicalizeMetadata($value);
            }
        }

        return $metadata;
    }

    /**
     * Build the persistent values for one provider-neutral tracking row.
     *
     * @return array<string, mixed>
     */
    private function notificationAttributes(
        PreparedMessage $message,
        CarbonImmutable $now,
    ): array {
        $notifiable = $message->context->notifiable;

        return [
            'id' => $message->correlationId,
            'correlation_id' => $message->correlationId,
            'queue_reference' => $message->queueReference,
            'mailer' => $message->mailer,
            'status' => MailDeliveryStatus::Pending,
            'message_category' => $message->context->category,
            'subject' => $message->subject,
            'from_email' => $message->from?->email,
            'from_name' => $message->from?->name,
            'to_recipients' => $this->recipientPayload($message->to),
            'cc_recipients' => $this->recipientPayload($message->cc),
            'bcc_recipients' => $this->recipientPayload($message->bcc),
            'primary_recipient_email' => $message->to[0]->email ?? null,
            'notifiable_type' => $notifiable?->type,
            'notifiable_id' => $notifiable?->identifier,
            'metadata' => $this->redactor->redact(
                $message->context->metadata,
            ),
            'status_changed_at' => $now,
        ];
    }

    /**
     * Return the stable attempt identity for one persisted notification.
     */
    private function attempt(MailNotification $notification): TrackingAttempt
    {
        return new TrackingAttempt(
            id: $notification->id,
            correlationId: $notification->correlation_id,
        );
    }

    /**
     * Record one terminal queued-Mailable failure inside a transaction.
     */
    private function recordQueuedFailure(
        PreparedMessage $message,
        Throwable $exception,
        string $queueReference,
    ): void {
        $fallback = $this->lockedQueuedFailureFallback($queueReference);

        if ($fallback instanceof MailNotification) {
            $this->failNotification($fallback, $exception);

            return;
        }

        $completedAttemptExists = MailNotification::query()
            ->where('queue_reference', $queueReference)
            ->where('status', '!=', MailDeliveryStatus::Pending->value)
            ->exists();

        if ($completedAttemptExists) {
            return;
        }

        if (! hash_equals($queueReference, $message->correlationId)) {
            throw new DomainException(
                'A queued failure fallback must use its queue reference as correlation identity.',
            );
        }

        $this->assertNotifiableTypeIsRegistered($message);
        $now = CarbonImmutable::now('UTC');
        $notification = MailNotification::query()->create(
            $this->notificationAttributes($message, $now),
        );

        $this->events->dispatch(new MailTrackingStarted(
            attempt: $this->attempt($notification),
            category: $notification->message_category,
        ));
        $this->failNotification($notification, $exception);
    }

    /**
     * Resolve an existing fallback without range-locking a missing identity.
     */
    private function lockedQueuedFailureFallback(
        string $queueReference,
    ): ?MailNotification {
        $fallback = MailNotification::query()
            ->where('correlation_id', $queueReference)
            ->where('queue_reference', $queueReference)
            ->first();

        if (! $fallback instanceof MailNotification) {
            return null;
        }

        $fallback = MailNotification::query()
            ->whereKey($fallback->id)
            ->lockForUpdate()
            ->first();

        if (! $fallback instanceof MailNotification) {
            return null;
        }

        $this->assertQueuedFailureFallbackIdentity(
            $fallback,
            $queueReference,
        );

        return $fallback;
    }

    /**
     * Reconcile a concurrent fallback insert in a fresh transaction snapshot.
     */
    private function reconcileQueuedFailureConflict(
        string $queueReference,
        Throwable $exception,
    ): bool {
        $collisions = MailNotification::query()
            ->where(
                static function (Builder $query) use ($queueReference): void {
                    $query
                        ->whereKey($queueReference)
                        ->orWhere('correlation_id', $queueReference);
                },
            )
            ->lockForUpdate()
            ->limit(2)
            ->get();

        if ($collisions->isEmpty()) {
            return false;
        }

        $notification = $collisions->count() === 1
            ? $collisions->first()
            : null;

        if (! $notification instanceof MailNotification) {
            throw new DomainException(
                'The queued failure correlation conflicts with another tracked delivery.',
            );
        }

        $this->assertQueuedFailureFallbackIdentity(
            $notification,
            $queueReference,
        );
        $this->failNotification($notification, $exception);

        return true;
    }

    /**
     * Require all fallback identities to remain fenced to the queue reference.
     */
    private function assertQueuedFailureFallbackIdentity(
        MailNotification $notification,
        string $queueReference,
    ): void {
        if (! hash_equals($notification->id, $queueReference)
            || ! hash_equals($notification->correlation_id, $queueReference)
            || ! is_string($notification->queue_reference)
            || ! hash_equals($notification->queue_reference, $queueReference)) {
            throw new DomainException(
                'The queued failure correlation conflicts with another tracked delivery.',
            );
        }
    }

    /**
     * Apply one idempotent local failure transition to a locked notification.
     */
    private function failNotification(
        MailNotification $notification,
        Throwable $exception,
    ): void {
        if (! $notification->status->canTransitionTo(MailDeliveryStatus::Failed)) {
            return;
        }

        $previousStatus = $notification->status;
        $now = CarbonImmutable::now('UTC');
        $updates = [
            'status' => MailDeliveryStatus::Failed,
            'failed_at' => $now,
            'status_changed_at' => $now,
        ];

        if ($notification->redacted_at === null) {
            $metadata = $notification->metadata ?? [];
            $metadata['failure'] = [
                'exception' => $exception::class,
            ];
            $updates['metadata'] = $metadata;
        }

        $notification->update($updates);
        $attempt = $this->attempt($notification);

        $this->events->dispatch(new MailTrackingFailed(
            correlationId: $attempt->correlationId,
            attemptId: $attempt->id,
            exceptionClass: $exception::class,
        ));
        $this->events->dispatch(new MailDeliveryStatusChanged(
            notificationId: $notification->id,
            previousStatus: $previousStatus,
            currentStatus: MailDeliveryStatus::Failed,
        ));
    }

    /**
     * Resolve and lock the notification correlated to a provider event.
     */
    private function notificationForEvent(VerifiedDeliveryEvent $event): MailNotification
    {
        $query = MailNotification::query()->lockForUpdate();

        if ($event->correlationId !== null) {
            $query->where('correlation_id', $event->correlationId);
        } elseif ($event->providerMessageId !== null) {
            $query->where(
                static function (Builder $query) use ($event): void {
                    $query
                        ->where('provider', $event->provider)
                        ->where('provider_message_id', $event->providerMessageId);
                },
            );
        } else {
            throw new DomainException(
                'A verified delivery event requires a correlation or provider message identifier.',
            );
        }

        $notifications = $query->limit(2)->get();

        if ($notifications->count() > 1) {
            throw new AmbiguousDeliveryEventException;
        }

        $notification = $notifications->first();

        if (! $notification instanceof MailNotification) {
            throw new UnmatchedDeliveryEventException;
        }

        if ($notification->provider !== null
            && ! hash_equals($notification->provider, $event->provider)) {
            throw new DomainException(
                'The provider event does not match the tracked mail provider.',
            );
        }

        if ($event->providerMessageId !== null
            && $notification->provider_message_id !== null
            && ! hash_equals(
                $notification->provider_message_id,
                $event->providerMessageId,
            )) {
            throw new DomainException(
                'The provider event does not match the tracked provider message.',
            );
        }

        return $notification;
    }

    /**
     * Prevent transport acceptance from replacing an established provider identity.
     */
    private function assertAcceptanceIdentityMatches(
        MailNotification $notification,
        ProviderAcceptance $acceptance,
    ): void {
        if ($notification->provider !== null
            && ! hash_equals(
                $notification->provider,
                $acceptance->messageId->provider,
            )) {
            throw new DomainException(
                'Transport acceptance does not match the tracked mail provider.',
            );
        }

        if ($notification->provider_message_id !== null
            && ! hash_equals(
                $notification->provider_message_id,
                $acceptance->messageId->value,
            )) {
            throw new DomainException(
                'Transport acceptance does not match the tracked provider message.',
            );
        }
    }

    /**
     * Ensure persisted aliases can be resolved without storing host class names.
     */
    private function assertNotifiableTypeIsRegistered(
        PreparedMessage $message,
    ): void {
        $notifiable = $message->context->notifiable;

        if ($notifiable !== null
            && $this->notifiableTypes->resolve($notifiable->type) === null) {
            throw new DomainException(sprintf(
                'Mail notification notifiable type [%s] is not registered.',
                $notifiable->type,
            ));
        }
    }

    /**
     * Convert recipient value objects into their persisted representation.
     *
     * @param  list<Recipient>  $recipients
     * @return list<array{email: string, name: string|null}>
     */
    private function recipientPayload(array $recipients): array
    {
        return array_map(
            static fn (Recipient $recipient): array => $recipient->toArray(),
            $recipients,
        );
    }
}
