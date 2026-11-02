<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\ClientWebhookEndpointFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Client-owned signed delivery callback subscription with an encrypted signing secret.
 *
 * @property list<string> $events
 * @property string $id
 * @property string $client_id
 * @property string $url
 * @property string $secret
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read client_id
 * @nvl-consumer-read url
 * @nvl-consumer-read events
 * @nvl-consumer-read enabled
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 * @nvl-consumer-read deleted_at
 */
#[Fillable(['client_id', 'url', 'secret', 'events', 'enabled'])]
#[Hidden(['secret'])]
class ClientWebhookEndpoint extends Model
{
    public const string TABLE = MailNotificationsTables::ClientWebhookEndpoints;

    /** @use HasFactory<ClientWebhookEndpointFactory> */
    use HasFactory;

    use HasUuids, SoftDeletes;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'events' => 'array', 'enabled' => 'boolean'];
    }

    /**
     * The consuming client owning this record.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Durable callback delivery intents created for this subscription.
     *
     * @return HasMany<ClientWebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ClientWebhookDelivery::class);
    }

    /**
     * Generate a new 32-byte callback signing key in the published whsec_ representation.
     */
    public static function newSecret(): string
    {
        return 'whsec_'.base64_encode(random_bytes(32));
    }

    protected static function newFactory(): ClientWebhookEndpointFactory
    {
        return ClientWebhookEndpointFactory::new();
    }
}
