<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use Nvl\MailNotifications\Exceptions\ManagedProviderWebhookRejected;
use Nvl\MailNotifications\Models\ProviderAccount;

/** Verifies provider signatures and normalizes bounded webhook payloads independently of HTTP transport.
 * @api
 */
class ProviderWebhookAdapter
{
    /**
     * Normalize the verified provider payload into bounded local delivery observations.
     *
     * @param  array<string, string>  $headers  Case-insensitive provider signature headers.
     * @return list<array{payload: array<string, mixed>, key: string}>
     */
    public function normalize(ProviderAccount $account, string $body, ?string $contentType, array $headers): array
    {
        if (! (in_array($account->provider, ['mailersend', 'sendgrid', 'mailgun'], true))) {
            throw new ManagedProviderWebhookRejected('unsupported_provider', 'The webhook provider is unsupported.');
        }
        if (strlen($body) > 262144) {
            throw new ManagedProviderWebhookRejected('payload_too_large', 'The webhook payload exceeds its size limit.');
        }
        if (! ($this->isJson($contentType))) {
            throw new ManagedProviderWebhookRejected('unsupported_content_type', 'The webhook content type must be JSON.');
        }
        $headers = $this->headers($headers);
        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ManagedProviderWebhookRejected('invalid_payload', 'Invalid webhook payload.');
        }
        if (! (is_array($payload))) {
            throw new ManagedProviderWebhookRejected('invalid_payload', 'Invalid webhook payload.');
        }

        return match ($account->provider) {
            'mailersend' => $this->mailerSend($headers, $account, $body, $payload),
            'sendgrid' => $this->sendGrid($headers, $account, $body, $payload),
            'mailgun' => $this->mailgun($account, $payload),
        };
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<array-key, mixed>  $payload
     * @return list<array{payload: array<string, mixed>, key: string}>
     */
    private function mailerSend(array $headers, ProviderAccount $account, string $body, array $payload): array
    {
        $signature = $headers['signature'] ?? '';
        $secret = $account->webhook_secret;
        $real = is_string($secret) && $secret !== '' && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
        $test = hash_equals(hash_hmac('sha256', $body, 'test_Am3L1GuOIc4blLUuHqAPxxwkZaJyEk8G'), $signature);
        if (! ($real || $test)) {
            throw new ManagedProviderWebhookRejected('invalid_signature', 'Invalid webhook signature.');
        }
        if (array_is_list($payload)) {
            throw new ManagedProviderWebhookRejected('invalid_payload', 'Invalid webhook payload.');
        }
        if ($test) {
            if (! (($payload['type'] ?? null) === 'webhook.test' && ! isset($payload['data']))) {
                throw new ManagedProviderWebhookRejected('invalid_signature', 'Invalid webhook signature.');
            }

            return [];
        }
        $validated = $this->validated($payload, [
            'type' => ['required', 'string', 'max:80'], 'created_at' => ['required', 'date'],
            'data' => ['required_unless:type,webhook.test', 'array'],
            'data.id' => ['required_unless:type,webhook.test', 'string', 'max:191'],
            'data.message_id' => ['required_if:type,activity.submitted,activity.sent,activity.delivered,activity.soft_bounced,activity.hard_bounced,activity.deferred,activity.opened,activity.opened_unique,activity.clicked,activity.clicked_unique,activity.unsubscribed,activity.spam_complaint,activity.suppressed', 'string', 'max:255'],
            'data.recipient' => ['required_if:type,activity.submitted', 'required_with:data.message_id', 'email:rfc', 'max:254'],
            'data.tags' => ['sometimes', 'nullable', 'array', 'max:20'], 'data.tags.*' => ['string', 'max:191'],
            'data.meta' => ['sometimes', 'array', 'max:30'],
        ]);

        return $validated['type'] === 'webhook.test' ? [] : [['payload' => $this->object($validated), 'key' => hash('sha256', $body)]];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<array-key, mixed>  $payload
     * @return list<array{payload: array<string, mixed>, key: string}>
     */
    private function sendGrid(array $headers, ProviderAccount $account, string $body, array $payload): array
    {
        $timestamp = $headers['x-twilio-email-event-webhook-timestamp'] ?? '';
        $signature = base64_decode($headers['x-twilio-email-event-webhook-signature'] ?? '', true);
        $publicKey = $account->webhook_public_key;
        if (! ($this->freshTimestamp($timestamp) && is_string($signature) && is_string($publicKey) && $publicKey !== '')) {
            throw new ManagedProviderWebhookRejected('invalid_signature', 'Invalid webhook signature.');
        }
        if (! str_contains($publicKey, '-----BEGIN PUBLIC KEY-----')) {
            $decoded = base64_decode($publicKey, true);
            if (! (is_string($decoded))) {
                throw new ManagedProviderWebhookRejected('invalid_signature', 'Invalid webhook verification key.');
            }
            $publicKey = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($decoded), 64, "\n")."-----END PUBLIC KEY-----\n";
        }
        $key = @openssl_pkey_get_public($publicKey);
        if (! ($key !== false && openssl_verify($timestamp.$body, $signature, $key, OPENSSL_ALGO_SHA256) === 1)) {
            throw new ManagedProviderWebhookRejected('invalid_signature', 'Invalid webhook signature.');
        }
        if (! (array_is_list($payload)) || $payload === [] || count($payload) > 500) {
            throw new ManagedProviderWebhookRejected('invalid_payload', 'Invalid webhook payload.');
        }
        // SendGrid retries a refused batch as a whole, so one malformed element
        // must not cost the verified events delivered beside it.
        $normalized = [];
        foreach ($payload as $rawEvent) {
            try {
                $normalized[] = $this->sendGridEvent($rawEvent);
            } catch (ManagedProviderWebhookRejected) {
                continue;
            }
        }
        $malformed = count($payload) - count($normalized);
        if ($normalized === []) {
            throw new ManagedProviderWebhookRejected('invalid_payload', 'Invalid webhook payload.');
        }
        if ($malformed > 0) {
            report(new ManagedProviderWebhookRejected('invalid_payload', sprintf('Skipped %d of %d malformed events in a verified SendGrid webhook batch.', $malformed, count($payload))));
        }

        return $normalized;
    }

    /**
     * Validate and normalize one element of a verified SendGrid batch.
     *
     * @return array{payload: array<string, mixed>, key: string}
     */
    private function sendGridEvent(mixed $rawEvent): array
    {
        $event = $this->object($this->validated($this->object($rawEvent), [
            'email' => ['required', 'email:rfc', 'max:254'],
            'timestamp' => ['required', 'numeric', 'min:0', 'max:4102444800'],
            'event' => ['required', 'string', 'max:80'],
            'sg_event_id' => ['required', 'string', 'max:180'],
            'sg_message_id' => ['nullable', 'string', 'max:255'],
            'attempt_id' => ['nullable', 'uuid'],
            'custom_args' => ['sometimes', 'array', 'max:20'],
            'custom_args.attempt_id' => ['nullable', 'uuid'],
            'type' => ['nullable', 'string', 'max:80'],
        ]));
        $type = match ($this->requiredString($event, 'event')) {
            'processed' => 'submitted', 'delivered' => 'delivered', 'deferred' => 'deferred',
            'open' => 'opened', 'click' => 'clicked', 'spamreport' => 'spam_complaint',
            'unsubscribe', 'group_unsubscribe' => 'unsubscribed',
            'bounce' => ($event['type'] ?? '') === 'blocked' ? 'soft_bounced' : 'hard_bounced',
            'dropped' => 'suppressed', default => 'unknown',
        };
        $data = [
            'id' => 'sg:'.$this->requiredString($event, 'sg_event_id'), 'message_id' => $this->optionalString($event, 'sg_message_id') ?? '',
            'recipient' => $this->requiredString($event, 'email'), 'correlation_id' => $this->optionalString($event, 'attempt_id') ?? $this->optionalString($event, 'custom_args.attempt_id'),
            'meta' => [],
        ];

        return ['payload' => ['type' => 'activity.'.$type, 'created_at' => CarbonImmutable::createFromTimestampUTC($this->timestamp($event, 'timestamp'))->toIso8601String(), 'data' => $data], 'key' => hash('sha256', 'sendgrid:'.$this->requiredString($event, 'sg_event_id'))];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<array{payload: array<string, mixed>, key: string}>
     */
    private function mailgun(ProviderAccount $account, array $payload): array
    {
        $signature = is_array($payload['signature'] ?? null) ? $payload['signature'] : [];
        $rawTimestamp = $signature['timestamp'] ?? '';
        if (! (is_string($rawTimestamp) || is_int($rawTimestamp))) {
            throw new ManagedProviderWebhookRejected('invalid_signature', 'Invalid webhook signature.');
        }
        $timestamp = (string) $rawTimestamp;
        $token = $signature['token'] ?? null;
        $provided = $signature['signature'] ?? null;
        $secret = $account->webhook_secret;
        if (! ($this->freshTimestamp($timestamp) && is_string($token) && preg_match('/\A[a-zA-Z0-9]{50}\z/', $token) && is_string($provided) && is_string($secret) && $secret !== '' && hash_equals(hash_hmac('sha256', $timestamp.$token, $secret), $provided))) {
            throw new ManagedProviderWebhookRejected('invalid_signature', 'Invalid webhook signature.');
        }
        $event = $this->validated($payload, [
            'event-data' => ['required', 'array'], 'event-data.event' => ['required', 'string', 'max:80'],
            'event-data.id' => ['required', 'string', 'max:180'],
            'event-data.timestamp' => ['required', 'numeric', 'min:0', 'max:4102444800'],
            'event-data.recipient' => ['required', 'email:rfc', 'max:254'],
            'event-data.severity' => ['nullable', 'in:temporary,permanent'],
            'event-data.message.headers.message-id' => ['required', 'string', 'max:253'],
            'event-data.user-variables' => ['sometimes', 'array', 'max:20'],
            'event-data.user-variables.attempt_id' => ['nullable', 'uuid'],
        ])['event-data'];
        $event = $this->object($event);
        $type = match ($this->requiredString($event, 'event')) {
            'accepted' => 'submitted', 'delivered' => 'delivered', 'opened' => 'opened', 'clicked' => 'clicked',
            'unsubscribed' => 'unsubscribed', 'complained' => 'spam_complaint',
            'temporary_fail' => 'deferred', 'permanent_fail' => 'hard_bounced',
            'failed' => ($event['severity'] ?? '') === 'permanent' ? 'hard_bounced' : 'deferred',
            default => 'unknown',
        };
        $data = ['id' => 'mg:'.$this->requiredString($event, 'id'), 'message_id' => '<'.trim($this->requiredString($event, 'message.headers.message-id'), '<>').'>', 'recipient' => $this->requiredString($event, 'recipient'), 'correlation_id' => $this->optionalString($event, 'user-variables.attempt_id'), 'meta' => []];

        return [['payload' => ['type' => 'activity.'.$type, 'created_at' => CarbonImmutable::createFromTimestampUTC($this->timestamp($event, 'timestamp'))->toIso8601String(), 'data' => $data], 'key' => hash('sha256', 'mailgun:'.$timestamp.':'.$token)]];
    }

    /**
     * Apply the provider schema and reject a failure as the documented webhook rejection.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, list<string>>  $rules
     * @return array<array-key, mixed>
     */
    private function validated(array $payload, array $rules): array
    {
        try {
            return Validator::make($payload, $rules)->validate();
        } catch (ValidationException) {
            throw new ManagedProviderWebhookRejected('invalid_payload', 'Invalid webhook payload.');
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (! (is_array($value))) {
            throw new ManagedProviderWebhookRejected('invalid_payload', 'Invalid webhook object.');
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (! (is_string($key))) {
                throw new ManagedProviderWebhookRejected('invalid_payload', 'Invalid webhook object key.');
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /** @param array<string, mixed> $event */
    private function requiredString(array $event, string $path): string
    {
        $value = data_get($event, $path);
        if (! (is_string($value))) {
            throw new ManagedProviderWebhookRejected('invalid_payload', 'Invalid webhook string.');
        }

        return $value;
    }

    /** @param array<string, mixed> $event */
    private function optionalString(array $event, string $path): ?string
    {
        $value = data_get($event, $path);
        if (! ($value === null || is_string($value))) {
            throw new ManagedProviderWebhookRejected('invalid_payload', 'Invalid webhook string.');
        }

        return $value;
    }

    /** @param array<string, mixed> $event */
    private function timestamp(array $event, string $path): int|float|string
    {
        $value = data_get($event, $path);
        if (! (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
            throw new ManagedProviderWebhookRejected('invalid_payload', 'Invalid webhook timestamp.');
        }

        return $value;
    }

    private function isJson(?string $contentType): bool
    {
        $type = strtolower(trim(explode(';', $contentType ?? '', 2)[0]));

        return in_array($type, ['json', 'application/json', 'application/x-json'], true)
            || preg_match('/^application\/[^\s\/]+\+json$/D', $type) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw new ManagedProviderWebhookRejected('invalid_payload', 'Webhook headers must contain string names and values.');
            }
            $normalized[strtolower(trim($name))] = trim($value);
        }

        return $normalized;
    }

    private function freshTimestamp(string $timestamp): bool
    {
        return preg_match('/\A[0-9]{1,12}\z/', $timestamp) === 1 && abs(now()->getTimestamp() - (int) $timestamp) <= 259200;
    }
}
