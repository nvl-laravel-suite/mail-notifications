<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\OwnerFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\AccountStatus;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Shared account identity grouping clients under provider ownership and mail defaults.
 *
 * @property-read OwnerMailSettings|null $mailSettings
 * @property int|null $broker_revision
 * @property AccountStatus $status
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read name
 * @nvl-consumer-read slug
 * @nvl-consumer-read status
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['name', 'slug', 'status'])]
class Owner extends Model
{
    public const string TABLE = MailNotificationsTables::Owners;

    /** @use HasFactory<OwnerFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    protected $attributes = ['status' => 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => AccountStatus::class, 'broker_revision' => 'integer'];
    }

    /**
     * Clients grouped beneath this owner.
     *
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * Provider accounts belonging to this owner or integration.
     *
     * @return HasMany<ProviderAccount, $this>
     */
    public function providerAccounts(): HasMany
    {
        return $this->hasMany(ProviderAccount::class);
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
     * The explicit mail settings stored for this identity.
     *
     * @return HasOne<OwnerMailSettings, $this>
     */
    public function mailSettings(): HasOne
    {
        return $this->hasOne(OwnerMailSettings::class);
    }

    /** Local mail eligibility; broker identity eligibility is verified by live introspection. */
    public function isActive(): bool
    {
        return $this->mailSettings?->status === AccountStatus::Active;
    }

    protected static function newFactory(): OwnerFactory
    {
        return OwnerFactory::new();
    }
}
