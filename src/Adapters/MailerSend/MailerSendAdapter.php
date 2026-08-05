<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Adapters\MailerSend;

use DomainException;
use Illuminate\Contracts\Config\Repository;
use JsonException;
use Nvl\MailNotifications\Contracts\ProviderAdapter;
use Nvl\MailNotifications\Contracts\ProviderConfigurationValidator;
use Nvl\MailNotifications\Contracts\ProviderMessageIdResolver;
use Nvl\MailNotifications\Contracts\WebhookEventNormalizer;
use Nvl\MailNotifications\Contracts\WebhookSignatureVerifier;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\TransportResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\MailNotifications\ValueObjects\VerifiedWebhook;
use Nvl\MailNotifications\ValueObjects\WebhookAcknowledgement;
use Nvl\MailNotifications\ValueObjects\WebhookRequest;
use Symfony\Component\Mime\Message;

/**
 * Provides opt-in MailerSend transport identity and webhook capabilities.
 */
final readonly class MailerSendAdapter implements ProviderAdapter, ProviderConfigurationValidator, ProviderMessageIdResolver, WebhookEventNormalizer, WebhookSignatureVerifier
{
    private const string PROVIDER = 'mailersend';

    private const string CONFIG_PREFIX = 'mail-notifications.providers.mailersend';

    private const string VALIDATION_SECRET = 'test_Am3L1GuOIc4blLUuHqAPxxwkZaJyEk8G';

    private const int MINIMUM_SIGNING_SECRET_BYTES = 16;

    private const int MAXIMUM_SECRET_BYTES = 4_096;

    /**
     * Create the isolated MailerSend adapter.
     */
    public function __construct(
        private Repository $config,
        private MailerSendWebhookNormalizer $normalizer,
    ) {}

    /**
     * Return the stable provider name exposed to core.
     */
    public function name(): string
    {
        return self::PROVIDER;
    }

    /**
     * Validate transport configuration and webhook secrets when webhooks are active.
     */
    public function validateConfiguration(bool $webhooksEnabled): void
    {
        $this->configuredMailers();
        $this->configuredHeaders(
            'message_id_headers',
            ['x-mailersend-message-id', 'x-message-id'],
        );

        if (! $webhooksEnabled) {
            return;
        }

        $secret = $this->signingSecret();
        $validationSecret = $this->validationSecret();

        if (hash_equals($validationSecret, $secret)) {
            throw new MailTrackingException(
                'The MailerSend webhook signing secret cannot be its public validation secret.',
            );
        }

        $this->configuredHeaders('signature_headers', ['signature']);
        $this->normalizer->validateConfiguration();
    }

    /**
     * Determine whether MailerSend attached a provider message identifier.
     */
    public function supports(TransportResult $result): bool
    {
        return $this->messageId($result) !== null;
    }

    /**
     * Resolve the MailerSend message identifier attached by the transport.
     */
    public function resolve(TransportResult $result): ?ProviderMessageId
    {
        $messageId = $this->messageId($result);

        return $messageId === null
            ? null
            : new ProviderMessageId(self::PROVIDER, $messageId);
    }

    /**
     * Verify the exact raw webhook body using MailerSend's signing secret.
     */
    public function verify(WebhookRequest $request): VerifiedWebhook
    {
        if ($request->provider !== self::PROVIDER) {
            throw new DomainException('The webhook provider does not match the MailerSend adapter.');
        }

        $signature = $this->signature($request);

        if ($signature === null || preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1) {
            throw new DomainException('The MailerSend webhook signature is invalid.');
        }

        $secret = $this->optionalSigningSecret();
        $validationSecret = $this->validationSecret();

        if ($secret !== null && hash_equals($validationSecret, $secret)) {
            throw new MailTrackingException(
                'The MailerSend webhook signing secret cannot be its public validation secret.',
            );
        }

        $matchesSigningSecret = $secret !== null
            && hash_equals(hash_hmac('sha256', $request->body, $secret), $signature);
        $matchesValidationSecret = hash_equals(
            hash_hmac('sha256', $request->body, $validationSecret),
            $signature,
        );

        if (! $matchesSigningSecret && ! $matchesValidationSecret) {
            if ($secret === null) {
                throw new MailTrackingException(
                    'A MailerSend webhook signing secret must be configured before processing activity webhooks.',
                );
            }

            throw new DomainException('The MailerSend webhook signature is invalid.');
        }

        $payload = $this->decodePayload($request->body);

        if ($matchesValidationSecret
            && ($payload['type'] ?? null) !== 'webhook.test') {
            throw new DomainException(
                'The MailerSend validation secret may authenticate only webhook.test.',
            );
        }

        return new VerifiedWebhook(self::PROVIDER, $payload);
    }

    /**
     * Decode an authenticated MailerSend JSON object without retaining its raw body.
     *
     * @return array<string, mixed>
     */
    private function decodePayload(string $body): array
    {
        try {
            $payload = json_decode(
                $body,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'The authenticated MailerSend webhook body is not valid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($payload)
            || ! str_starts_with(ltrim($body), '{')) {
            throw new DomainException(
                'The authenticated MailerSend webhook body must be a JSON object.',
            );
        }

        $normalized = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw new DomainException(
                    'The authenticated MailerSend webhook body requires string keys.',
                );
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Normalize one authenticated MailerSend delivery activity.
     */
    public function normalize(
        VerifiedWebhook $webhook,
    ): VerifiedDeliveryEvent|WebhookAcknowledgement {
        return $this->normalizer->normalize($webhook);
    }

    /**
     * Resolve an identifier from configured transport header aliases.
     */
    private function messageId(TransportResult $result): ?string
    {
        $original = $result->message
            ->getSymfonySentMessage()
            ->getOriginalMessage();

        if (! $original instanceof Message) {
            return null;
        }

        $transportHeader = $original->getHeaders()->get('x-mailersend-message-id');

        if ($transportHeader !== null) {
            return $this->normalizeMessageId($transportHeader->getBodyAsString());
        }

        if (! in_array(
            mb_strtolower(trim($result->mailer)),
            $this->configuredMailers(),
            true,
        )) {
            return null;
        }

        foreach ($this->configuredHeaders(
            'message_id_headers',
            ['x-mailersend-message-id', 'x-message-id'],
        ) as $headerName) {
            if ($headerName === 'x-mailersend-message-id') {
                continue;
            }

            $header = $original->getHeaders()->get($headerName);

            if ($header === null) {
                continue;
            }

            $messageId = $this->normalizeMessageId($header->getBodyAsString());

            if ($messageId !== null) {
                return $messageId;
            }
        }

        return null;
    }

    /**
     * Normalize a MailerSend message identifier for package storage.
     */
    private function normalizeMessageId(string $messageId): ?string
    {
        $normalized = trim($messageId, " \t\n\r\0\x0B<>");

        if ($normalized === '') {
            return null;
        }

        $localPart = strstr($normalized, '@', true);
        $normalized = $localPart === false ? $normalized : $localPart;

        if ($normalized === '' || mb_strlen($normalized) > 255) {
            throw new DomainException(
                'The MailerSend message identifier exceeds package storage limits.',
            );
        }

        return $normalized;
    }

    /**
     * Return the configured non-empty webhook signing secret.
     */
    private function signingSecret(): string
    {
        $secret = $this->optionalSigningSecret();

        if ($secret === null) {
            throw new MailTrackingException(
                'A MailerSend webhook signing secret must be configured before verification.',
            );
        }

        return $secret;
    }

    /**
     * Return the configured signing secret when activity webhooks are enabled.
     */
    private function optionalSigningSecret(): ?string
    {
        $secret = $this->config->get(self::CONFIG_PREFIX.'.signing_secret');

        if ($secret === null || $secret === '') {
            return null;
        }

        if (! is_string($secret)
            || trim($secret) === ''
            || strlen($secret) < self::MINIMUM_SIGNING_SECRET_BYTES
            || strlen($secret) > self::MAXIMUM_SECRET_BYTES) {
            throw new MailTrackingException(
                sprintf(
                    'The MailerSend webhook signing secret must contain between %d and %d bytes.',
                    self::MINIMUM_SIGNING_SECRET_BYTES,
                    self::MAXIMUM_SECRET_BYTES,
                ),
            );
        }

        return $secret;
    }

    /**
     * Return the fixed or explicitly configured MailerSend URL-validation secret.
     */
    private function validationSecret(): string
    {
        $secret = $this->config->get(
            self::CONFIG_PREFIX.'.validation_secret',
            self::VALIDATION_SECRET,
        );

        if (! is_string($secret)
            || strlen($secret) > self::MAXIMUM_SECRET_BYTES
            || ! hash_equals(self::VALIDATION_SECRET, $secret)) {
            throw new MailTrackingException(
                'The MailerSend webhook validation secret must equal MailerSend\'s fixed URL-validation secret.',
            );
        }

        return $secret;
    }

    /**
     * Return the Laravel mailer names allowed to expose generic provider headers.
     *
     * @return list<string>
     */
    private function configuredMailers(): array
    {
        $configured = $this->config->get(self::CONFIG_PREFIX.'.mailers', ['mailersend']);

        if (! is_array($configured) || $configured === []) {
            throw new MailTrackingException(
                'Configured MailerSend [mailers] must be a non-empty array of mailer names.',
            );
        }

        $mailers = [];

        foreach ($configured as $mailer) {
            if (! is_string($mailer)) {
                throw new MailTrackingException(
                    'Configured MailerSend [mailers] must contain only mailer names.',
                );
            }

            $normalized = mb_strtolower(trim($mailer));

            if ($normalized === '' || mb_strlen($normalized) > 128) {
                throw new MailTrackingException(
                    'Configured MailerSend [mailers] contains an invalid mailer name.',
                );
            }

            $mailers[] = $normalized;
        }

        return array_values(array_unique($mailers));
    }

    /**
     * Return the first configured signature header present on the request.
     */
    private function signature(WebhookRequest $request): ?string
    {
        foreach ($this->configuredHeaders('signature_headers', ['signature']) as $headerName) {
            $signature = $request->headers[$headerName] ?? null;

            if (is_string($signature) && $signature !== '') {
                return $signature;
            }
        }

        return null;
    }

    /**
     * Read and validate one configured list of case-insensitive header names.
     *
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private function configuredHeaders(string $key, array $defaults): array
    {
        $configured = $this->config->get(self::CONFIG_PREFIX.'.'.$key, $defaults);

        if (! is_array($configured) || $configured === []) {
            throw new MailTrackingException(
                "Configured MailerSend [{$key}] must be a non-empty array of header names.",
            );
        }

        $headers = [];

        foreach ($configured as $header) {
            if (! is_string($header)) {
                throw new MailTrackingException(
                    "Configured MailerSend [{$key}] must contain only header names.",
                );
            }

            $normalized = mb_strtolower(trim($header));

            if ($normalized === '' || mb_strlen($normalized) > 128) {
                throw new MailTrackingException(
                    "Configured MailerSend [{$key}] contains an invalid header name.",
                );
            }

            $headers[] = $normalized;
        }

        return array_values(array_unique($headers));
    }
}
