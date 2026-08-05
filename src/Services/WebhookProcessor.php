<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Contracts\WebhookEventNormalizer;
use Nvl\MailNotifications\Contracts\WebhookSignatureVerifier;
use Nvl\MailNotifications\Enums\UnknownWebhookEventPolicy;
use Nvl\MailNotifications\Enums\UnmatchedWebhookEventPolicy;
use Nvl\MailNotifications\Events\MailWebhookAcknowledged;
use Nvl\MailNotifications\Events\WebhookEventAmbiguous;
use Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException;
use Nvl\MailNotifications\ValueObjects\TransitionResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement;
use Nvl\MailNotifications\ValueObjects\WebhookRequest;

/**
 * Verifies, normalizes, and persists one provider webhook through a registered adapter.
 */
final readonly class WebhookProcessor
{
    /**
     * Create the provider webhook processor.
     */
    public function __construct(
        private ProviderRegistry $providers,
        private TrackingLifecycle $lifecycle,
        private Repository $config,
        private ?MailTrackingEventDispatcher $events = null,
    ) {}

    /**
     * Process one bounded webhook request using the named provider adapter.
     */
    public function process(
        string $provider,
        WebhookRequest $request,
    ): TransitionResult|WebhookAcknowledgement {
        if (! $this->enabled()) {
            throw new MailTrackingException(
                'Mail provider webhook processing is disabled.',
            );
        }

        $this->assertPostMethod($request);
        $this->assertJsonContentType($request);

        $adapter = $this->providers->resolve($provider);
        $adapterName = trim($adapter->name());

        if (! hash_equals($adapterName, $request->provider)) {
            throw new DomainException(
                'The webhook request provider does not match the selected adapter.',
            );
        }

        if (strlen($request->body) > $this->maximumPayloadBytes()) {
            throw new DomainException(
                'The provider webhook payload exceeds the configured size limit.',
            );
        }

        if (! $adapter instanceof WebhookSignatureVerifier
            || ! $adapter instanceof WebhookEventNormalizer) {
            throw new MailTrackingException(
                "Mail provider adapter [{$adapterName}] must implement webhook verification and normalization.",
            );
        }

        $webhook = $adapter->verify($request);

        if (! hash_equals($adapterName, trim($webhook->provider))) {
            throw new DomainException(
                'The verified webhook provider does not match the selected adapter.',
            );
        }

        $event = $adapter->normalize($webhook);

        if ($event instanceof WebhookAcknowledgement) {
            if (! hash_equals($adapterName, $event->provider)) {
                throw new DomainException(
                    'The acknowledged webhook provider does not match the selected adapter.',
                );
            }

            return $this->acknowledge($event);
        }

        if (! hash_equals($adapterName, $event->provider)) {
            throw new DomainException(
                'The normalized webhook event provider does not match the selected adapter.',
            );
        }

        try {
            return $this->lifecycle->apply($event);
        } catch (AmbiguousDeliveryEventException) {
            return $this->handleAmbiguousEvent($event);
        } catch (UnmatchedDeliveryEventException $exception) {
            return $this->handleUnmatchedEvent($event, $exception);
        }
    }

    /**
     * Determine whether provider webhook processing is active.
     */
    public function enabled(): bool
    {
        $packageEnabled = $this->boolean(
            'mail-notifications.enabled',
            true,
        );
        $webhooksEnabled = $this->boolean(
            'mail-notifications.webhooks.enabled',
            true,
        );

        return $packageEnabled && $webhooksEnabled;
    }

    /**
     * Return the configured maximum raw webhook payload size in bytes.
     */
    public function maximumPayloadBytes(): int
    {
        $configured = $this->config->get(
            'mail-notifications.webhooks.max_payload_bytes',
            1_048_576,
        );

        if (! is_int($configured) || $configured < 1) {
            throw new MailTrackingException(
                'The mail provider webhook payload limit must be a positive integer.',
            );
        }

        return $configured;
    }

    /**
     * Return the configured authenticated unknown-event handling policy.
     */
    public function unknownEventPolicy(): UnknownWebhookEventPolicy
    {
        return UnknownWebhookEventPolicy::fromConfig(
            $this->config->get(
                'mail-notifications.webhooks.unknown_event_policy',
                UnknownWebhookEventPolicy::Acknowledge->value,
            ),
        );
    }

    /**
     * Return the configured exact JSON media types accepted from providers.
     *
     * @return list<string>
     */
    public function allowedContentTypes(): array
    {
        $configured = $this->config->get(
            'mail-notifications.webhooks.allowed_content_types',
            ['application/json'],
        );

        if (! is_array($configured)
            || ! array_is_list($configured)
            || $configured === []) {
            throw new MailTrackingException(
                'Allowed provider webhook content types must be a non-empty list.',
            );
        }

        $contentTypes = [];

        foreach ($configured as $contentType) {
            if (! is_string($contentType)) {
                throw new MailTrackingException(
                    'Allowed provider webhook content types must contain only strings.',
                );
            }

            $normalized = mb_strtolower(trim($contentType));

            if (preg_match(
                '/\Aapplication\/(?:[a-z0-9!#$&^_.+-]+\+)?json\z/D',
                $normalized,
            ) !== 1) {
                throw new MailTrackingException(
                    'Allowed provider webhook content types must be exact application JSON media types without parameters.',
                );
            }

            $contentTypes[] = $normalized;
        }

        return array_values(array_unique($contentTypes));
    }

    /**
     * Return the configured tracked-message lookup miss policy.
     */
    public function unmatchedEventPolicy(): UnmatchedWebhookEventPolicy
    {
        return UnmatchedWebhookEventPolicy::fromConfig(
            $this->config->get(
                'mail-notifications.webhooks.unmatched_events.policy',
                UnmatchedWebhookEventPolicy::RetryThenAcknowledge->value,
            ),
        );
    }

    /**
     * Return the bounded race-recovery grace period for unmatched events.
     */
    public function unmatchedEventRetryGraceSeconds(): int
    {
        $configured = $this->config->get(
            'mail-notifications.webhooks.unmatched_events.retry_grace_seconds',
            300,
        );

        if (! is_int($configured)
            || $configured < 30
            || $configured > 3_600) {
            throw new MailTrackingException(
                'The unmatched webhook retry grace must be an integer between 30 and 3600 seconds.',
            );
        }

        return $configured;
    }

    /**
     * Read one webhook switch without unsafe truthy-value coercion.
     */
    private function boolean(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);

        if (! is_bool($value)) {
            throw new MailTrackingException(
                "Mail notification configuration [{$key}] must be a boolean.",
            );
        }

        return $value;
    }

    /**
     * Retry recent lookup races, then safely acknowledge genuinely untracked mail.
     */
    private function handleUnmatchedEvent(
        VerifiedDeliveryEvent $event,
        UnmatchedDeliveryEventException $exception,
    ): WebhookAcknowledgement {
        $policy = $this->unmatchedEventPolicy();

        if ($policy === UnmatchedWebhookEventPolicy::Reject) {
            throw $exception;
        }

        if ($policy === UnmatchedWebhookEventPolicy::RetryThenAcknowledge
            && $event->occurredAt->greaterThan(
                CarbonImmutable::now('UTC')->subSeconds(
                    $this->unmatchedEventRetryGraceSeconds(),
                ),
            )) {
            throw $exception;
        }

        return $this->acknowledge(new WebhookAcknowledgement(
            provider: $event->provider,
            event: $this->eventName($event),
            reason: 'unmatched_event',
        ));
    }

    /**
     * Acknowledge an ambiguous identity once without lifecycle mutation or retry.
     */
    private function handleAmbiguousEvent(
        VerifiedDeliveryEvent $event,
    ): WebhookAcknowledgement {
        $this->events?->dispatch(new WebhookEventAmbiguous(
            provider: $event->provider,
            providerEventId: $event->eventId,
            providerMessageId: $event->providerMessageId,
            correlationId: $event->correlationId,
        ));

        return $this->acknowledge(new WebhookAcknowledgement(
            provider: $event->provider,
            event: $this->eventName($event),
            reason: 'ambiguous_event',
        ));
    }

    /**
     * Resolve one bounded safe provider event name for acknowledgements.
     */
    private function eventName(VerifiedDeliveryEvent $event): string
    {
        $eventName = $event->metadata['event'] ?? $event->status->value;

        if (! is_string($eventName)
            || trim($eventName) === ''
            || mb_strlen(trim($eventName)) > 128) {
            return $event->status->value;
        }

        return trim($eventName);
    }

    /**
     * Dispatch one safe observational acknowledgement and return it to the host.
     */
    private function acknowledge(
        WebhookAcknowledgement $acknowledgement,
    ): WebhookAcknowledgement {
        $this->events?->dispatch(
            new MailWebhookAcknowledged($acknowledgement),
        );

        return $acknowledgement;
    }

    /**
     * Reject supplied HTTP methods other than POST.
     */
    private function assertPostMethod(WebhookRequest $request): void
    {
        if ($request->method !== null && $request->method !== 'POST') {
            throw new DomainException(
                'Provider webhook requests must use the POST method.',
            );
        }
    }

    /**
     * Require an allowlisted JSON content type for real HTTP requests.
     */
    private function assertJsonContentType(WebhookRequest $request): void
    {
        $header = $request->headers['content-type'] ?? null;

        if ($header === null) {
            if ($request->method !== null) {
                throw new DomainException(
                    'Provider webhook requests require a JSON Content-Type header.',
                );
            }

            return;
        }

        $contentType = mb_strtolower(trim(explode(';', $header, 2)[0]));

        if (! in_array($contentType, $this->allowedContentTypes(), true)) {
            throw new DomainException(
                'The provider webhook Content-Type is not allowed.',
            );
        }
    }
}
