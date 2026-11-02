<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\ClientFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\AccountStatus;
use Nvl\MailNotifications\Services\MailSettingsResolver;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Consuming application identity whose mail settings and credentials remain independently controllable.
 *
 * @property-read ClientMailSettings|null $mailSettings
 * @property int|null $broker_revision
 * @property AccountStatus $status
 * @property-read Owner $owner
 * @property string $id
 * @property string $owner_id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read owner_id
 * @nvl-consumer-read name
 * @nvl-consumer-read slug
 * @nvl-consumer-read status
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['owner_id', 'name', 'slug', 'status'])]
class Client extends Authenticatable
{
    public const string TABLE = MailNotificationsTables::Clients;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    protected $attributes = ['status' => 'active'];

    /**
     * Retain the persisted Media uploader identity unless the host configured an explicit morph alias.
     */
    public function getMorphClass(): string
    {
        $morphType = parent::getMorphClass();

        return $morphType === self::class ? 'Nvl\\MailNotifications\\Managed\\Models\\Client' : $morphType;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => AccountStatus::class, 'broker_revision' => 'integer'];
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
     * The explicit mail settings stored for this identity.
     *
     * @return HasOne<ClientMailSettings, $this>
     */
    public function mailSettings(): HasOne
    {
        return $this->hasOne(ClientMailSettings::class);
    }

    /**
     * Independently revocable credentials issued for this client.
     *
     * @return HasMany<ClientApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ClientApiKey::class);
    }

    /**
     * Reusable files belonging to this client.
     *
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * This client's marketing preferences, separate from provider suppressions.
     *
     * @return HasMany<MarketingOptOut, $this>
     */
    public function marketingOptOuts(): HasMany
    {
        return $this->hasMany(MarketingOptOut::class);
    }

    /**
     * Delivery intents belonging to this client.
     *
     * @return HasMany<EmailMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * Match rules scoped to this client.
     *
     * @return HasMany<EmailFlagRule, $this>
     */
    public function flagRules(): HasMany
    {
        return $this->hasMany(EmailFlagRule::class);
    }

    /** Local mail eligibility; broker identity eligibility is verified by live introspection. */
    public function isActive(): bool
    {
        return $this->mailSettings?->status === AccountStatus::Active && $this->owner->isActive();
    }

    /**
     * Resolve owner defaults and explicit client overrides for the current persisted identity.
     *
     * @return array{provider_account_id: ?string, sender_identity_id: ?string, from_email: ?string, from_name: ?string, reply_to_email: ?string, reply_to_name: ?string}
     */
    public function effectiveMailSettings(): array
    {
        return (new MailSettingsResolver)->resolve($this);
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }
}
