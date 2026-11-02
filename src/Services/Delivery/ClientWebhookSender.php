<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Enums\AccountStatus;
use Nvl\MailNotifications\Models\ClientWebhookDelivery;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Delivers immutable signed callbacks with target validation, fenced leases and persisted retry outcomes.
 */
class ClientWebhookSender
{
    private const array RETRY_SECONDS = [60, 300, 900, 3600, 14400, 43200, 86400];

    public function __construct(private ClientWebhookTarget $target) {}

    /**
     * Claim and deliver a persisted signed callback, retaining retry or terminal outcome under the current lease.
     */
    public function send(string $id): void
    {
        $delivery = DB::connection(ManagedConfiguration::connection())->transaction(function () use ($id): ?ClientWebhookDelivery {
            $delivery = ClientWebhookDelivery::query()->lockForUpdate()->find($id);
            if (! $delivery || $delivery->status !== 'pending' || $delivery->next_attempt_at?->isFuture()) {
                return null;
            }
            $endpoint = $delivery->endpoint()->with('client.owner', 'client.mailSettings')->first();
            if (! $endpoint || ! $endpoint->client || ! $endpoint->enabled || $endpoint->trashed() || $endpoint->client->status !== AccountStatus::Active || ! $endpoint->client->isActive()) {
                $delivery->update(['status' => 'skipped', 'dispatched_at' => null, 'error_code' => 'endpoint_inactive']);

                return null;
            }
            if ($delivery->attempts >= 8) {
                $delivery->update(['status' => 'failed', 'dispatched_at' => null, 'error_code' => 'attempts_exhausted']);

                return null;
            }
            $delivery->update(['status' => 'processing', 'attempts' => $delivery->attempts + 1, 'last_attempt_at' => now(), 'lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinute()]);
            $delivery->setRelation('endpoint', $endpoint);

            return $delivery;
        });
        if (! $delivery) {
            return;
        }
        $status = null;
        $retryAfter = 0;
        $error = null;
        $terminal = false;
        $sink = null;
        try {
            $endpoint = $delivery->endpoint ?? throw new \RuntimeException('The webhook endpoint is unavailable.');
            $target = $this->target->resolve($endpoint->url);
            $timestamp = (string) now()->timestamp;
            $key = base64_decode(substr($endpoint->secret, 6), true);
            if ($key === false || strlen($key) !== 32) {
                throw new \RuntimeException('Invalid signing key.');
            }
            $signature = 'v1,'.base64_encode(hash_hmac('sha256', $delivery->event_id.'.'.$timestamp.'.'.$delivery->payload, $key, true));
            $address = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];
            $sink = tmpfile();
            $response = Http::connectTimeout(3)->timeout(10)->setHandler(new CurlHandler)->withOptions([
                'allow_redirects' => false, 'proxy' => '', 'protocols' => [$target['scheme']], 'sink' => $sink, 'decode_content' => false,
                'curl' => [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$address]],
                'on_headers' => function (ResponseInterface $response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 65536) {
                        throw new \RuntimeException('Callback response exceeded the size limit.');
                    }
                },
                'progress' => function (int $size, int $downloaded): void {
                    if ($size > 65536 || $downloaded > 65536) {
                        throw new \RuntimeException('Callback response exceeded the size limit.');
                    }
                },
            ])->withHeaders(['webhook-id' => $delivery->event_id, 'webhook-timestamp' => $timestamp, 'webhook-signature' => $signature])
                ->withBody($delivery->payload, 'application/json')->post($endpoint->url);
            $status = $response->status();
            $rawRetryAfter = $response->header('Retry-After');
            if ($rawRetryAfter !== '') {
                $retryAfter = ctype_digit($rawRetryAfter) ? (int) $rawRetryAfter : max(0, (strtotime($rawRetryAfter) ?: now()->getTimestamp()) - now()->getTimestamp());
                $retryAfter = min(86400, $retryAfter);
            }
            $error = $response->successful() ? null : 'http_error';
            $terminal = $status === 410;
        } catch (ValidationException) {
            $error = 'unsafe_url';
            $terminal = true;
        } catch (Throwable) {
            $error = 'request_failed';
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }
        DB::connection(ManagedConfiguration::connection())->transaction(function () use ($delivery, $status, $error, $terminal, $retryAfter): void {
            $current = ClientWebhookDelivery::query()->lockForUpdate()->find($delivery->id);
            if (! $current || $current->status !== 'processing' || $current->lease_token !== $delivery->lease_token) {
                return;
            }
            $success = $status !== null && $status >= 200 && $status < 300;
            $retry = ! $success && ! $terminal && $current->attempts < 8;
            $current->update([
                'status' => $success ? 'delivered' : ($retry ? 'pending' : 'failed'), 'http_status' => $status, 'error_code' => $error,
                'next_attempt_at' => $retry ? now()->addSeconds(max(self::RETRY_SECONDS[$current->attempts - 1], $retryAfter)) : null,
                'delivered_at' => $success ? now() : null, 'lease_token' => null, 'lease_until' => null, 'dispatched_at' => null,
            ]);
            if ($status === 410) {
                $current->endpoint()->update(['enabled' => false]);
            }
        });
    }
}
