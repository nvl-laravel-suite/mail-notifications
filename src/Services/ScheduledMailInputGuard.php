<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use JsonException;
use Nvl\MailNotifications\Exceptions\ScheduledMailException;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;

/**
 * Bounds durable scheduled-mail input before host factory code can inspect it.
 */
final readonly class ScheduledMailInputGuard
{
    /**
     * Create the durable-input guard.
     */
    public function __construct(
        private ScheduledMailConfiguration $configuration,
    ) {}

    /**
     * Require a JSON-serializable payload within the configured byte budget.
     *
     * @param  array<string, mixed>  $payload
     */
    public function assertPayload(array $payload): void
    {
        $this->assertStringKeys($payload, 'payload');

        try {
            $serialized = json_encode(
                $payload,
                JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new ScheduledMailException(
                'Scheduled mail payload must be JSON serializable.',
            );
        }

        if (strlen($serialized) > $this->configuration->maximumPayloadBytes()) {
            throw new ScheduledMailException(
                'Scheduled mail payload exceeds the configured byte limit.',
            );
        }
    }

    /**
     * Require top-level metadata to retain its documented object shape.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function assertMetadata(array $metadata): void
    {
        $this->assertStringKeys($metadata, 'metadata');
    }

    /**
     * Require the deduplicated envelope to stay within its fanout budget.
     */
    public function assertRecipients(ScheduledRecipients $recipients): void
    {
        $count = count($recipients->to)
            + count($recipients->cc)
            + count($recipients->bcc);

        if ($count > $this->configuration->maximumRecipients()) {
            throw new ScheduledMailException(
                'Scheduled mail recipients exceed the configured limit.',
            );
        }
    }

    /**
     * Reject list-shaped top-level data before it becomes unreadable history.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function assertStringKeys(array $values, string $label): void
    {
        foreach (array_keys($values) as $key) {
            if (! is_string($key)) {
                throw new ScheduledMailException(sprintf(
                    'Scheduled mail %s must use string keys.',
                    $label,
                ));
            }
        }
    }
}
