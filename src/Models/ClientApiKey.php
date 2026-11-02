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
use Nvl\MailNotifications\Database\Factories\ClientApiKeyFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Hashed client credential with explicit abilities, expiry and independent revocation.
 *
 * @property list<string> $abilities
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_used_at
 * @property string $id
 * @property string $client_id
 * @property string $name
 * @property string $prefix
 * @property string $token_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read client_id
 * @nvl-consumer-read name
 * @nvl-consumer-read prefix
 * @nvl-consumer-read abilities
 * @nvl-consumer-read expires_at
 * @nvl-consumer-read revoked_at
 * @nvl-consumer-read last_used_at
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['client_id', 'name', 'prefix', 'token_hash', 'abilities', 'expires_at', 'revoked_at', 'last_used_at'])]
#[Hidden(['token_hash'])]
class ClientApiKey extends Model
{
    public const string TABLE = MailNotificationsTables::ClientApiKeys;

    /** @use HasFactory<ClientApiKeyFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    public const array ABILITIES = ['client:read', 'client:write', 'keys:manage', 'assets:read', 'assets:write', 'messages:read', 'messages:send', 'messages:inspect', 'configuration:read', 'flags:read', 'flags:write', 'webhooks:manage'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['abilities' => 'array', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime'];
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
     * Check the stored ability list, including an explicitly issued wildcard credential.
     */
    public function allows(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }

    /**
     * Reject expired or revoked credentials independently of the current client lifecycle checks.
     */
    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    protected static function newFactory(): ClientApiKeyFactory
    {
        return ClientApiKeyFactory::new();
    }
}
