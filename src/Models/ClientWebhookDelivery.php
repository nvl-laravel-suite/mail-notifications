<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\ClientWebhookDeliveryFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Immutable client callback payload with durable dispatch, retry and completion state.
 *
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $lease_until
 * @property string $id
 * @property string $client_webhook_endpoint_id
 * @property string $event_id
 * @property string $event_key
 * @property string $event_type
 * @property string $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $lease_token
 * @property int|null $http_status
 * @property string|null $error_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read client_webhook_endpoint_id
 * @nvl-consumer-read event_id
 * @nvl-consumer-read event_type
 * @nvl-consumer-read status
 * @nvl-consumer-read attempts
 * @nvl-consumer-read next_attempt_at
 * @nvl-consumer-read last_attempt_at
 * @nvl-consumer-read delivered_at
 * @nvl-consumer-read http_status
 * @nvl-consumer-read error_code
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['client_webhook_endpoint_id', 'event_id', 'event_key', 'event_type', 'payload', 'status', 'attempts', 'next_attempt_at', 'dispatched_at', 'last_attempt_at', 'delivered_at', 'lease_token', 'lease_until', 'http_status', 'error_code'])]
#[Hidden(['payload', 'lease_token'])]
class ClientWebhookDelivery extends Model
{
    public const string TABLE = MailNotificationsTables::ClientWebhookDeliveries;

    /** @use HasFactory<ClientWebhookDeliveryFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['attempts' => 'integer', 'http_status' => 'integer', 'next_attempt_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'last_attempt_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime'];
    }

    /**
     * The client-owned subscription for this callback delivery intent.
     *
     * @return BelongsTo<ClientWebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(ClientWebhookEndpoint::class, 'client_webhook_endpoint_id')->withTrashed();
    }

    protected static function newFactory(): ClientWebhookDeliveryFactory
    {
        return ClientWebhookDeliveryFactory::new();
    }
}
