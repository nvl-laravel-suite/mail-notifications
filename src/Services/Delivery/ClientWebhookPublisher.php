<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Jobs\DeliverClientWebhook;
use Nvl\MailNotifications\Models\ClientWebhookDelivery;
use Nvl\MailNotifications\Models\ClientWebhookEndpoint;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Throwable;

/**
 * Persists immutable client callback intents and dispatches them after the source commit.
 */
class ClientWebhookPublisher
{
    public const array EVENT_TYPES = ['message.accepted', 'message.submitted', 'message.failed', 'message.blocked', 'message.cancelled', 'message.unknown', 'message.retry_scheduled', 'message.sent', 'message.delivered', 'message.soft_bounced', 'message.hard_bounced', 'message.deferred', 'message.opened', 'message.opened_unique', 'message.clicked', 'message.clicked_unique', 'message.unsubscribed', 'message.spam_complaint', 'message.suppressed'];

    /**
     * Persist subscribed client callback payloads and enqueue their delivery after the source transaction commits.
     */
    public function publish(EmailMessage $message, string $type, string $eventKey, ?string $recipientId = null, ?CarbonImmutable $occurredAt = null): void
    {
        $endpoints = ClientWebhookEndpoint::query()->where('client_id', $message->client_id)->where('enabled', true)->get()
            ->filter(fn (ClientWebhookEndpoint $endpoint): bool => in_array('*', $endpoint->events, true) || in_array($type, $endpoint->events, true));
        if ($endpoints->isEmpty()) {
            return;
        }
        $eventId = (string) Str::uuid();
        $payload = json_encode([
            'version' => 1, 'id' => $eventId, 'type' => $type, 'occurred_at' => ($occurredAt ?? now())->toIso8601String(), 'client_id' => $message->client_id,
            'data' => [
                'message_id' => $message->id, 'status' => $message->status,
                'references' => $message->references()->get()->map(fn ($reference): array => ['type' => $reference->type, 'id' => $reference->reference_id])->all(),
                'recipient_id' => $recipientId, 'template_version_id' => $message->email_template_version_id,
                'composition_revision_id' => $message->composition_revision_id, 'error_code' => $message->error_code,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($endpoints as $endpoint) {
            $delivery = ClientWebhookDelivery::query()->firstOrCreate(
                ['client_webhook_endpoint_id' => $endpoint->id, 'event_key' => $eventKey],
                ['event_id' => $eventId, 'event_type' => $type, 'payload' => $payload, 'status' => 'pending', 'next_attempt_at' => now()],
            );
            if ($delivery->wasRecentlyCreated) {
                DB::connection(ManagedConfiguration::connection())->afterCommit(fn () => $this->dispatch($delivery->id));
            }
        }
    }

    /**
     * Require a durable configured callback queue before enabling outbound callback work.
     */
    public function durableConnection(): string
    {
        $connection = ManagedConfiguration::get('webhooks.connection') ?: config('queue.default');
        if (! is_string($connection) || ! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw ValidationException::withMessages(['webhooks' => 'Client webhooks require a durable queue connection.']);
        }

        return $connection;
    }

    /**
     * Queue one persisted callback intent without reconstructing its immutable event body.
     */
    public function dispatch(string $id): bool
    {
        try {
            $connection = $this->durableConnection();
            $claimed = ClientWebhookDelivery::query()->whereKey($id)->where('status', 'pending')->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(5)))->update(['dispatched_at' => now()]);
            if ($claimed === 0) {
                return false;
            }
            DeliverClientWebhook::dispatch($id)->onConnection($connection)->onQueue(ManagedConfiguration::string('webhooks.queue', 'webhooks'));

            return true;
        } catch (Throwable) {
            ClientWebhookDelivery::query()->whereKey($id)->where('status', 'pending')->update(['dispatched_at' => null]);

            return false;
        }
    }

    /**
     * Requeue a bounded set of due callback intents and recover expired dispatch leases.
     */
    public function recover(int $limit = 100): int
    {
        ClientWebhookDelivery::query()->where('status', 'processing')->where('lease_until', '<=', now())
            ->update(['status' => 'pending', 'lease_token' => null, 'lease_until' => null, 'dispatched_at' => null, 'error_code' => 'worker_interrupted']);
        $ids = ClientWebhookDelivery::query()->where('status', 'pending')->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(5)))->oldest()->limit(min(1000, max(1, $limit)))->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new \UnexpectedValueException('Webhook delivery identifiers must be strings.');
            }
            if ($this->dispatch($id)) {
                $count++;
            }
        }

        return $count;
    }
}
