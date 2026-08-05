<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Nvl\MailNotifications\Models\ScheduledMailMessage;

/**
 * Carries a validated persisted message into a host factory.
 *
 * Initial submission must not follow intended delivery. Later retry attempts
 * may have a later availability because this value also fences retry backoff.
 */
final readonly class ScheduledMessageData
{
    /**
     * Intended recipient delivery instant normalized to UTC.
     */
    public CarbonImmutable $scheduledFor;

    /**
     * Current attempt eligibility instant normalized to UTC.
     */
    public CarbonImmutable $availableAt;

    /**
     * Create the immutable factory input.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $factoryAlias,
        public int $payloadVersion,
        public array $payload,
        public ScheduledRecipients $recipients,
        CarbonImmutable $scheduledFor,
        public ?NotifiableReference $notifiable,
        public array $metadata,
        public int $attempt,
        ?CarbonImmutable $availableAt = null,
    ) {
        $this->scheduledFor = $scheduledFor->setTimezone('UTC');
        $this->availableAt = ($availableAt ?? $scheduledFor)
            ->setTimezone('UTC');

        if ($attempt === 1
            && $this->availableAt->greaterThan($this->scheduledFor)) {
            throw new InvalidArgumentException(
                'Persisted scheduled message initial availability must be at or before its scheduled delivery time.',
            );
        }
    }

    /**
     * Restore factory input from one claimed scheduled message.
     */
    public static function fromModel(ScheduledMailMessage $message): self
    {
        $payload = $message->getAttribute('payload');
        $to = $message->getAttribute('to_recipients');
        $cc = $message->getAttribute('cc_recipients');
        $bcc = $message->getAttribute('bcc_recipients');
        $metadata = $message->getAttribute('metadata');

        if (! is_array($payload)
            || ! is_array($to)
            || ($cc !== null && ! is_array($cc))
            || ($bcc !== null && ! is_array($bcc))
            || ($metadata !== null && ! is_array($metadata))) {
            throw new InvalidArgumentException(
                'Persisted scheduled message data is invalid.',
            );
        }

        $payload = self::stringKeyed(
            values: $payload,
            label: 'payload',
        );
        $metadata = self::stringKeyed(
            values: $metadata ?? [],
            label: 'metadata',
        );
        $notifiable = null;

        if ($message->notifiable_type !== null
            || $message->notifiable_id !== null) {
            if ($message->notifiable_type === null
                || $message->notifiable_id === null) {
                throw new InvalidArgumentException(
                    'Persisted scheduled message notifiable data is incomplete.',
                );
            }

            $notifiable = new NotifiableReference(
                type: $message->notifiable_type,
                identifier: $message->notifiable_id,
            );
        }

        return new self(
            id: $message->id,
            factoryAlias: $message->factory_alias,
            payloadVersion: $message->payload_version,
            payload: $payload,
            recipients: ScheduledRecipients::fromPersisted(
                to: $to,
                cc: $cc ?? [],
                bcc: $bcc ?? [],
            ),
            scheduledFor: $message->scheduled_for,
            notifiable: $notifiable,
            metadata: $metadata,
            attempt: $message->attempts,
            availableAt: $message->available_at,
        );
    }

    /**
     * Require a string-keyed object representation at a factory boundary.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values, string $label): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Persisted scheduled message %s must use string keys.',
                    $label,
                ));
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
