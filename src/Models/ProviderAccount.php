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
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\ProviderAccountFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\AccountStatus;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Owner-controlled sending integration with encrypted credentials and lifecycle status.
 *
 * @property AccountStatus $status
 * @property array{api_key?: string, region?: string, domain?: string, host?: string, port?: int, username?: string|null, password?: string|null, encryption?: string|null, scheme?: string|null} $credentials
 * @property string|null $webhook_public_key
 * @property string|null $webhook_secret
 * @property string $id
 * @property string $owner_id
 * @property string $name
 * @property string $provider
 * @property string|null $integration_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read owner_id
 * @nvl-consumer-read name
 * @nvl-consumer-read provider
 * @nvl-consumer-read status
 * @nvl-consumer-read integration_id
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['owner_id', 'name', 'provider', 'status', 'credentials', 'webhook_secret', 'webhook_public_key', 'integration_id'])]
#[Hidden(['credentials', 'webhook_secret', 'webhook_public_key'])]
class ProviderAccount extends Model
{
    public const string TABLE = MailNotificationsTables::ProviderAccounts;

    /** @use HasFactory<ProviderAccountFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (ProviderAccount $account): void {
            if ($account->integration_id === null || $account->isDirty('provider') || $account->isDirty('integration_id')) {
                $account->integration_id = Integration::where('key', $account->provider)->first()?->id;
            }
        });
    }

    /**
     * The integration catalogue entry defining this account's driver capabilities.
     *
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * Domains synchronized from this provider account.
     *
     * @return HasMany<SendingDomain, $this>
     */
    public function sendingDomains(): HasMany
    {
        return $this->hasMany(SendingDomain::class);
    }

    /**
     * Persisted synchronization progress for this provider account.
     *
     * @return HasMany<ProviderSyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(ProviderSyncRun::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => AccountStatus::class, 'credentials' => 'encrypted:array', 'webhook_secret' => 'encrypted', 'webhook_public_key' => 'encrypted'];
    }

    /**
     * The shared owner controlling this identity or provider configuration.
     *
     * @return BelongsTo<Owner, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /**
     * Sender identities belonging to this owner, account or domain.
     *
     * @return HasMany<SenderIdentity, $this>
     */
    public function senderIdentities(): HasMany
    {
        return $this->hasMany(SenderIdentity::class);
    }

    /**
     * Require an active account and an active associated owner.
     */
    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active && $this->owner?->isActive() === true;
    }

    protected static function newFactory(): ProviderAccountFactory
    {
        return ProviderAccountFactory::new();
    }
}
