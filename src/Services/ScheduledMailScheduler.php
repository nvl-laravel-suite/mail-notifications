<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Contracts\SensitiveDataRedactor;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Events\ScheduledMailCancelled;
use Nvl\MailNotifications\Events\ScheduledMailReplaced;
use Nvl\MailNotifications\Events\ScheduledMailRescheduled;
use Nvl\MailNotifications\Events\ScheduledMailScheduled;
use Nvl\MailNotifications\Exceptions\ScheduledMailException;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;

/**
 * Owns scheduling, cancellation, and rescheduling write transactions.
 *
 * Transaction ownership is intentional because this class is the stable public
 * write boundary used by host applications for scheduled-mail mutations.
 */
final readonly class ScheduledMailScheduler
{
    /**
     * Create the public scheduled-mail mutation boundary.
     */
    public function __construct(
        private ScheduledMailConfiguration $configuration,
        private ScheduledMessageFactoryRegistry $factories,
        private SensitiveDataRedactor $redactor,
        private MailNotificationNotifiableTypeRegistry $notifiableTypes,
        private MailTrackingEventDispatcher $events,
        private ScheduledMailInputGuard $input,
        private SensitiveStorageCodec $sensitiveStorage,
    ) {}

    /**
     * Persist one validated message for future delivery.
     */
    public function schedule(ScheduleMailData $data): ScheduledMailMessage
    {
        $this->ensureEnabled();
        $attributes = $this->validatedAttributes($data);
        $message = new ScheduledMailMessage;

        return $message->getConnection()->transaction(
            function () use ($attributes): ScheduledMailMessage {
                $message = ScheduledMailMessage::query()->create([
                    ...$attributes,
                    'status' => ScheduledMailStatus::Pending,
                ]);
                $this->events->dispatch(new ScheduledMailScheduled(
                    messageId: $message->id,
                    factoryAlias: $message->factory_alias,
                    payloadVersion: $message->payload_version,
                    scheduledFor: $message->scheduled_for,
                    availableAt: $message->available_at,
                ));

                return $message;
            },
        );
    }

    /**
     * Cancel one message that has not been claimed.
     */
    public function cancel(string $messageId): ScheduledMailMessage
    {
        $this->ensureEnabled();
        $message = new ScheduledMailMessage;

        return $message->getConnection()->transaction(function () use (
            $messageId,
        ): ScheduledMailMessage {
            $cancelledAt = CarbonImmutable::now('UTC');
            $updated = ScheduledMailMessage::query()
                ->whereKey($messageId)
                ->where('status', ScheduledMailStatus::Pending->value)
                ->update([
                    'status' => ScheduledMailStatus::Cancelled->value,
                    'cancelled_at' => DatabaseTimestamp::format($cancelledAt),
                ]);

            if ($updated !== 1) {
                throw new ScheduledMailException(
                    'Only pending scheduled mail may be cancelled.',
                );
            }

            $this->events->dispatch(new ScheduledMailCancelled(
                messageId: $messageId,
                cancelledAt: $cancelledAt,
            ));

            return ScheduledMailMessage::query()->findOrFail($messageId);
        });
    }

    /**
     * Move one unclaimed message to a new delivery and submission schedule.
     */
    public function reschedule(
        string $messageId,
        CarbonImmutable $scheduledFor,
        ?CarbonImmutable $availableAt = null,
    ): ScheduledMailMessage {
        $this->ensureEnabled();
        $timing = $this->normalizeTiming($scheduledFor, $availableAt);
        $message = new ScheduledMailMessage;

        return $message->getConnection()->transaction(function () use (
            $messageId,
            $timing,
        ): ScheduledMailMessage {
            $message = ScheduledMailMessage::query()
                ->whereKey($messageId)
                ->where('status', ScheduledMailStatus::Pending->value)
                ->lockForUpdate()
                ->first();

            if (! $message instanceof ScheduledMailMessage) {
                throw new ScheduledMailException(
                    'Only pending scheduled mail may be rescheduled.',
                );
            }

            $updated = ScheduledMailMessage::query()
                ->whereKey($messageId)
                ->where('status', ScheduledMailStatus::Pending->value)
                ->where('attempts', $message->attempts)
                ->update([
                    'scheduled_for' => DatabaseTimestamp::format(
                        $timing['scheduled_for'],
                    ),
                    'available_at' => DatabaseTimestamp::format(
                        $timing['available_at'],
                    ),
                    'last_error' => null,
                ]);

            if ($updated !== 1) {
                throw new ScheduledMailException(
                    'Only pending scheduled mail may be rescheduled.',
                );
            }

            $this->events->dispatch(new ScheduledMailRescheduled(
                messageId: $message->id,
                previousScheduledFor: $message->scheduled_for,
                previousAvailableAt: $message->available_at,
                scheduledFor: $timing['scheduled_for'],
                availableAt: $timing['available_at'],
            ));

            return ScheduledMailMessage::query()->findOrFail($messageId);
        });
    }

    /**
     * Atomically replace every host-owned field on one pending message.
     */
    public function replacePending(
        string $messageId,
        ScheduleMailData $data,
    ): ScheduledMailMessage {
        $this->ensureEnabled();
        $attributes = $this->validatedAttributes($data);
        $model = new ScheduledMailMessage;

        return $model->getConnection()->transaction(function () use (
            $messageId,
            $attributes,
        ): ScheduledMailMessage {
            $message = ScheduledMailMessage::query()
                ->whereKey($messageId)
                ->where('status', ScheduledMailStatus::Pending->value)
                ->lockForUpdate()
                ->first();

            if (! $message instanceof ScheduledMailMessage) {
                throw new ScheduledMailException(
                    'Only pending scheduled mail may be replaced.',
                );
            }

            if ($attributes['max_attempts'] <= $message->attempts) {
                throw new ScheduledMailException(
                    'Replacement max attempts must exceed attempts already made.',
                );
            }

            $storageAttributes = [
                ...$attributes,
                'scheduled_for' => DatabaseTimestamp::format(
                    $attributes['scheduled_for'],
                ),
                'available_at' => DatabaseTimestamp::format(
                    $attributes['available_at'],
                ),
                'payload' => $this->sensitiveStorage->encodeArray(
                    'scheduled_message.payload',
                    $attributes['payload'],
                ),
                'to_recipients' => $this->sensitiveStorage->encodeArray(
                    'scheduled_message.to_recipients',
                    $attributes['to_recipients'],
                ),
                'cc_recipients' => $this->sensitiveStorage->encodeArray(
                    'scheduled_message.cc_recipients',
                    $attributes['cc_recipients'],
                ),
                'bcc_recipients' => $this->sensitiveStorage->encodeArray(
                    'scheduled_message.bcc_recipients',
                    $attributes['bcc_recipients'],
                ),
                'metadata' => $this->sensitiveStorage->encodeArray(
                    'scheduled_message.metadata',
                    $attributes['metadata'],
                ),
            ];
            $updated = ScheduledMailMessage::query()
                ->whereKey($messageId)
                ->where('status', ScheduledMailStatus::Pending->value)
                ->where('attempts', $message->attempts)
                ->update([
                    ...$storageAttributes,
                    'claim_token' => null,
                    'locked_until' => null,
                    'last_error' => null,
                    'sent_at' => null,
                    'failed_at' => null,
                    'cancelled_at' => null,
                ]);

            if ($updated !== 1) {
                throw new ScheduledMailException(
                    'Only pending scheduled mail may be replaced.',
                );
            }

            $this->events->dispatch(new ScheduledMailReplaced(
                messageId: $message->id,
                previousFactoryAlias: $message->factory_alias,
                factoryAlias: $attributes['factory_alias'],
                previousPayloadVersion: $message->payload_version,
                payloadVersion: $attributes['payload_version'],
                previousScheduledFor: $message->scheduled_for,
                previousAvailableAt: $message->available_at,
                scheduledFor: $attributes['scheduled_for'],
                availableAt: $attributes['available_at'],
            ));

            return ScheduledMailMessage::query()->findOrFail($messageId);
        });
    }

    /**
     * Require the opt-in scheduling capability.
     */
    private function ensureEnabled(): void
    {
        if (! $this->configuration->enabled()) {
            throw new ScheduledMailException(
                'Scheduled mail is disabled by configuration.',
            );
        }
    }

    /**
     * Validate and normalize every persisted host-owned scheduling field.
     *
     * @return array{
     *     factory_alias: string,
     *     payload_version: int,
     *     payload: array<string, mixed>,
     *     to_recipients: list<array{email: string, name: string|null}>,
     *     cc_recipients: list<array{email: string, name: string|null}>,
     *     bcc_recipients: list<array{email: string, name: string|null}>,
     *     scheduled_for: CarbonImmutable,
     *     available_at: CarbonImmutable,
     *     max_attempts: int,
     *     notifiable_type: string|null,
     *     notifiable_id: string|null,
     *     metadata: array<string, mixed>
     * }
     */
    private function validatedAttributes(ScheduleMailData $data): array
    {
        $this->input->assertPayload($data->payload);
        $this->input->assertMetadata($data->metadata);
        $this->input->assertRecipients($data->recipients);
        $factory = $this->factories->resolve(
            alias: $data->factoryAlias,
            version: $data->payloadVersion,
        );
        $factory->validate($data->payloadVersion, $data->payload);

        if ($data->notifiable !== null
            && $this->notifiableTypes->resolve($data->notifiable->type) === null) {
            throw new ScheduledMailException(sprintf(
                'Scheduled mail notifiable type [%s] is not registered.',
                $data->notifiable->type,
            ));
        }

        return [
            'factory_alias' => $data->factoryAlias,
            'payload_version' => $data->payloadVersion,
            'payload' => $data->payload,
            'to_recipients' => $data->recipients->toPayload(),
            'cc_recipients' => $data->recipients->ccPayload(),
            'bcc_recipients' => $data->recipients->bccPayload(),
            'scheduled_for' => $data->scheduledFor,
            'available_at' => $data->availableAt,
            'max_attempts' => $data->maxAttempts
                ?? $this->configuration->defaultMaxAttempts(),
            'notifiable_type' => $data->notifiable?->type,
            'notifiable_id' => $data->notifiable?->identifier,
            'metadata' => $this->redactor->redact($data->metadata),
        ];
    }

    /**
     * Normalize a caller-owned delivery and initial submission schedule.
     *
     * @return array{
     *     scheduled_for: CarbonImmutable,
     *     available_at: CarbonImmutable
     * }
     */
    private function normalizeTiming(
        CarbonImmutable $scheduledFor,
        ?CarbonImmutable $availableAt,
    ): array {
        $scheduledFor = $scheduledFor->setTimezone('UTC');
        $availableAt = ($availableAt ?? $scheduledFor)->setTimezone('UTC');

        if ($availableAt->greaterThan($scheduledFor)) {
            throw new ScheduledMailException(
                'Scheduled mail availability must be at or before its scheduled delivery time.',
            );
        }

        return [
            'scheduled_for' => $scheduledFor,
            'available_at' => $availableAt,
        ];
    }
}
