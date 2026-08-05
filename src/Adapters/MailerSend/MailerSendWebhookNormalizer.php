<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Adapters\MailerSend;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Config\Repository;
use JsonException;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\UnknownWebhookEventPolicy;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\Support\TrackingHeaders;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\MailNotifications\ValueObjects\VerifiedWebhook;
use Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement;
use Throwable;

/**
 * Converts authenticated MailerSend v2 and compatible activity payloads to core events.
 */
final readonly class MailerSendWebhookNormalizer
{
    private const string PROVIDER = 'mailersend';

    /**
     * @var array<string, MailDeliveryStatus>
     */
    private const array STATUS_BY_EVENT = [
        'sent' => MailDeliveryStatus::Accepted,
        'delivered' => MailDeliveryStatus::Delivered,
        'deferred' => MailDeliveryStatus::Delayed,
        'opened' => MailDeliveryStatus::Opened,
        'opened_unique' => MailDeliveryStatus::Opened,
        'clicked' => MailDeliveryStatus::Clicked,
        'clicked_unique' => MailDeliveryStatus::Clicked,
        'soft_bounced' => MailDeliveryStatus::Delayed,
        'hard_bounced' => MailDeliveryStatus::Bounced,
        'bounced' => MailDeliveryStatus::Bounced,
        'spam_complaint' => MailDeliveryStatus::Complained,
        'spam' => MailDeliveryStatus::Complained,
        'complaint' => MailDeliveryStatus::Complained,
        'complained' => MailDeliveryStatus::Complained,
        'unsubscribed' => MailDeliveryStatus::Unsubscribed,
        'unsubscribe' => MailDeliveryStatus::Unsubscribed,
    ];

    /**
     * Create the isolated provider payload normalizer.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Validate generic authenticated unknown-event handling configuration.
     */
    public function validateConfiguration(): void
    {
        $this->unknownEventPolicy();
        $this->maximumPastAgeSeconds();
        $this->maximumFutureSkewSeconds();
    }

    /**
     * Normalize one authenticated MailerSend activity.
     */
    public function normalize(
        VerifiedWebhook $webhook,
    ): VerifiedDeliveryEvent|WebhookAcknowledgement {
        if ($webhook->provider !== self::PROVIDER) {
            throw new DomainException('The verified webhook does not belong to MailerSend.');
        }

        $event = $this->eventName($webhook->payload);
        $occurredAt = $this->occurredAt($webhook->payload);
        $this->assertTimestampBounds($occurredAt);

        if ($event === 'webhook.test') {
            return new WebhookAcknowledgement(
                provider: self::PROVIDER,
                event: $event,
                reason: 'provider_validation',
            );
        }

        $status = self::STATUS_BY_EVENT[$event] ?? null;

        if (! $status instanceof MailDeliveryStatus) {
            if ($this->unknownEventPolicy() === UnknownWebhookEventPolicy::Acknowledge) {
                return new WebhookAcknowledgement(
                    provider: self::PROVIDER,
                    event: $event,
                    reason: 'unsupported_event',
                );
            }

            throw new DomainException(
                "The MailerSend webhook event [{$event}] is not supported.",
            );
        }

        $providerMessageId = $this->providerMessageId($webhook->payload);
        $correlationId = $this->correlationId($webhook->payload);

        if ($providerMessageId === null && $correlationId === null) {
            throw new DomainException(
                'The MailerSend webhook requires a message or correlation identifier.',
            );
        }

        $metadata = $this->metadata($webhook->payload, $event);
        $eventId = $this->eventId(
            payload: $webhook->payload,
            event: $event,
            status: $status,
            occurredAt: $occurredAt,
            providerMessageId: $providerMessageId,
            correlationId: $correlationId,
            metadata: $metadata,
        );

        return new VerifiedDeliveryEvent(
            provider: self::PROVIDER,
            eventId: $eventId,
            status: $status,
            occurredAt: $occurredAt,
            providerMessageId: $providerMessageId,
            correlationId: $correlationId,
            metadata: $metadata,
        );
    }

    /**
     * Resolve and normalize the provider activity type.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventName(array $payload): string
    {
        $event = $this->firstString($payload, [
            'type',
            'event',
            'data.event',
            'data.type',
        ]);

        if ($event === null) {
            throw new DomainException('The MailerSend webhook event type is missing.');
        }

        $normalized = mb_strtolower(trim($event));

        if (str_starts_with($normalized, 'activity.')) {
            $normalized = mb_substr($normalized, mb_strlen('activity.'));
        }

        if ($normalized === '' || mb_strlen($normalized) > 128) {
            throw new DomainException('The MailerSend webhook event type is invalid.');
        }

        return $normalized;
    }

    /**
     * Resolve the MailerSend message identifier from v2 or compatible payloads.
     *
     * @param  array<string, mixed>  $payload
     */
    private function providerMessageId(array $payload): ?string
    {
        $messageId = $this->firstString($payload, [
            'data.message_id',
            'message_id',
            'data.message.id',
            'data.email.message.id',
            'message.id',
        ]);

        if ($messageId === null) {
            return null;
        }

        $normalized = trim($messageId, " \t\n\r\0\x0B<>");
        $localPart = strstr($normalized, '@', true);
        $normalized = $localPart === false ? $normalized : $localPart;

        if ($normalized === '' || mb_strlen($normalized) > 255) {
            throw new DomainException('The MailerSend message identifier is invalid.');
        }

        return $normalized;
    }

    /**
     * Resolve an optional internal tracking UUID from provider metadata or headers.
     *
     * @param  array<string, mixed>  $payload
     */
    private function correlationId(array $payload): ?string
    {
        $correlationId = $this->firstString($payload, [
            'correlation_id',
            'tracking_id',
            'x-nvl-mail-tracking-id',
            'data.correlation_id',
            'data.tracking_id',
            'data.x-nvl-mail-tracking-id',
        ]);

        if ($correlationId !== null) {
            return trim($correlationId);
        }

        foreach ([
            'meta',
            'metadata',
            'headers',
            'data.meta',
            'data.metadata',
            'data.headers',
        ] as $path) {
            $container = $this->valueAtPath($payload, $path);

            if (! is_array($container)) {
                continue;
            }

            foreach ($container as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    continue;
                }

                $normalizedKey = str_replace('_', '-', mb_strtolower(trim($key)));

                if (in_array($normalizedKey, [
                    'correlation-id',
                    'tracking-id',
                    mb_strtolower(TrackingHeaders::CORRELATION),
                ], true)) {
                    return trim($value);
                }
            }
        }

        return null;
    }

    /**
     * Resolve the required provider occurrence time and convert it to UTC.
     *
     * @param  array<string, mixed>  $payload
     */
    private function occurredAt(array $payload): CarbonImmutable
    {
        $value = $this->firstScalar($payload, [
            'created_at',
            'data.created_at',
            'timestamp',
            'data.timestamp',
        ]);

        if (! is_int($value) && ! is_string($value)) {
            throw new DomainException('The MailerSend webhook timestamp is missing.');
        }

        try {
            if (is_int($value) || preg_match('/\A\d+\z/D', $value) === 1) {
                $timestamp = (int) $value;

                if ($timestamp < 0) {
                    throw new DomainException('The MailerSend webhook timestamp is invalid.');
                }

                return CarbonImmutable::createFromTimestampUTC($timestamp);
            }

            return CarbonImmutable::parse($value)->utc();
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(
                'The MailerSend webhook timestamp is invalid.',
                previous: $exception,
            );
        }
    }

    /**
     * Resolve a stable provider event identifier or deterministic fingerprint.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, bool|string>  $metadata
     */
    private function eventId(
        array $payload,
        string $event,
        MailDeliveryStatus $status,
        CarbonImmutable $occurredAt,
        ?string $providerMessageId,
        ?string $correlationId,
        array $metadata,
    ): string {
        $providerEventId = $this->firstString($payload, [
            'data.id',
            'event_id',
            'data.event_id',
            'id',
        ]);

        if ($providerEventId !== null) {
            $providerEventId = trim($providerEventId);

            if ($providerEventId !== '' && mb_strlen($providerEventId) <= 255) {
                return $providerEventId;
            }
        }

        $fingerprint = [
            'correlation_id' => $correlationId,
            'event' => $event,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt->format('Y-m-d\TH:i:s.u\Z'),
            'provider_message_id' => $providerMessageId,
            'status' => $status->value,
        ];

        try {
            $encoded = json_encode(
                $this->canonicalize($fingerprint),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'The MailerSend webhook event fingerprint could not be generated.',
                previous: $exception,
            );
        }

        return self::PROVIDER.':'.hash('sha256', $encoded);
    }

    /**
     * Select a bounded allowlist of useful, non-sensitive event metadata.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, bool|string>
     */
    private function metadata(array $payload, string $event): array
    {
        $metadata = ['event' => $event];

        $this->addBoundedMetadata(
            $metadata,
            'recipient',
            $this->firstString($payload, [
                'data.email',
                'recipient',
                'data.recipient',
                'data.to',
                'to',
            ]),
            254,
        );
        $this->addBoundedMetadata(
            $metadata,
            'email_id',
            $this->firstString($payload, ['data.email_id', 'email_id']),
            255,
        );
        $this->addBoundedMetadata(
            $metadata,
            'domain_id',
            $this->firstString($payload, ['data.domain_id', 'domain_id']),
            255,
        );

        if (in_array($event, ['soft_bounced', 'hard_bounced', 'bounced'], true)) {
            $this->addBoundedMetadata(
                $metadata,
                'bounce_type',
                $this->firstString($payload, [
                    'data.bounce_type',
                    'bounce_type',
                    'data.type',
                ]) ?? $event,
                128,
            );
            $this->addBoundedMetadata(
                $metadata,
                'bounce_reason',
                $this->firstString($payload, [
                    'data.reason',
                    'reason',
                    'data.description',
                    'description',
                ]),
                1_024,
            );
        }

        if (in_array($event, ['opened_unique', 'clicked_unique'], true)) {
            $metadata['unique'] = true;
        }

        if (in_array($event, ['clicked', 'clicked_unique'], true)) {
            $url = $this->firstString($payload, ['data.url', 'url']);
            $hostname = $url === null ? null : parse_url($url, PHP_URL_HOST);

            if (is_string($hostname)) {
                $this->addBoundedMetadata($metadata, 'link_host', $hostname, 253);
            }
        }

        return $metadata;
    }

    /**
     * Add one trimmed and bounded string to the metadata allowlist.
     *
     * @param  array<string, bool|string>  $metadata
     */
    private function addBoundedMetadata(
        array &$metadata,
        string $key,
        ?string $value,
        int $maximumLength,
    ): void {
        if ($value === null) {
            return;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return;
        }

        $metadata[$key] = mb_substr($normalized, 0, $maximumLength);
    }

    /**
     * Resolve the first non-empty string at one of the supplied dot paths.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     */
    private function firstString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($payload, $path);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolve the first scalar value at one of the supplied dot paths.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     */
    private function firstScalar(array $payload, array $paths): bool|float|int|string|null
    {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($payload, $path);

            if (is_scalar($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolve a nested payload value using a dot-delimited path.
     *
     * @param  array<string, mixed>  $payload
     */
    private function valueAtPath(array $payload, string $path): mixed
    {
        $value = $payload;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Return the generic policy for authenticated unsupported webhook events.
     */
    private function unknownEventPolicy(): UnknownWebhookEventPolicy
    {
        return UnknownWebhookEventPolicy::fromConfig(
            $this->config->get(
                'mail-notifications.webhooks.unknown_event_policy',
                UnknownWebhookEventPolicy::Acknowledge->value,
            ),
        );
    }

    /**
     * Reject stale or implausibly future provider occurrence timestamps.
     */
    private function assertTimestampBounds(CarbonImmutable $occurredAt): void
    {
        $now = CarbonImmutable::now('UTC');

        if ($occurredAt->lessThan($now->subSeconds($this->maximumPastAgeSeconds()))) {
            throw new DomainException(
                'The MailerSend webhook timestamp is older than the configured acceptance window.',
            );
        }

        if ($occurredAt->greaterThan($now->addSeconds($this->maximumFutureSkewSeconds()))) {
            throw new DomainException(
                'The MailerSend webhook timestamp exceeds the configured future skew.',
            );
        }
    }

    /**
     * Return a past-age window at least as long as the provider retry horizon.
     */
    private function maximumPastAgeSeconds(): int
    {
        return $this->boundedTimestampSetting(
            'maximum_past_age_seconds',
            604_800,
            259_200,
            2_592_000,
        );
    }

    /**
     * Return the bounded future clock skew allowance.
     */
    private function maximumFutureSkewSeconds(): int
    {
        return $this->boundedTimestampSetting(
            'maximum_future_skew_seconds',
            300,
            0,
            3_600,
        );
    }

    /**
     * Read one strict MailerSend timestamp bound.
     */
    private function boundedTimestampSetting(
        string $key,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        $value = $this->config->get(
            'mail-notifications.providers.mailersend.timestamp_bounds.'.$key,
            $default,
        );

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new MailTrackingException(
                "MailerSend webhook timestamp bound [{$key}] must be an integer between {$minimum} and {$maximum} seconds.",
            );
        }

        return $value;
    }

    /**
     * Recursively sort associative values before hashing a fallback event identifier.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->canonicalize($nestedValue);
        }

        return $value;
    }
}
