<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\SenderIdentityFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Nvl\MailNotifications\Support\SenderDomainMatchExpression;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Verified or pending sender identity belonging to one owner and provider account.
 *
 * @property CarbonImmutable|null $verified_at
 * @property-read SendingDomain|null $sendingDomain
 * @property string $id
 * @property string $owner_id
 * @property string $provider_account_id
 * @property string $email
 * @property string|null $name
 * @property string|null $sending_domain_id
 * @property string|null $remote_id
 * @property string $status
 * @property string $verification_source
 * @property string|null $reply_to_email
 * @property string|null $reply_to_name
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_seen_sync_run_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read owner_id
 * @nvl-consumer-read provider_account_id
 * @nvl-consumer-read sending_domain_id
 * @nvl-consumer-read email
 * @nvl-consumer-read name
 * @nvl-consumer-read status
 * @nvl-consumer-read verification_source
 * @nvl-consumer-read reply_to_email
 * @nvl-consumer-read reply_to_name
 * @nvl-consumer-read verified_at
 * @nvl-consumer-read last_synced_at
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['owner_id', 'provider_account_id', 'email', 'name', 'verified_at', 'sending_domain_id', 'remote_id', 'status', 'verification_source', 'reply_to_email', 'reply_to_name', 'last_synced_at'])]
class SenderIdentity extends Model
{
    public const string TABLE = MailNotificationsTables::SenderIdentities;

    /** @use HasFactory<SenderIdentityFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime'];
    }

    /**
     * The provider domain associated with this sender identity.
     *
     * @return BelongsTo<SendingDomain, $this>
     */
    public function sendingDomain(): BelongsTo
    {
        return $this->belongsTo(SendingDomain::class);
    }

    /**
     * Determine whether this sender is verified under the persisted provider status.
     */
    public function isVerified(): bool
    {
        if ($this->sending_domain_id !== null) {
            $domain = $this->sendingDomain;
            if ($domain === null || ! $domain->isVerified() || $domain->owner_id !== $this->owner_id || $domain->provider_account_id !== $this->provider_account_id) {
                return false;
            }
            if ($this->verification_source !== 'provider' && strcasecmp(substr(strrchr($this->email, '@') ?: '', 1), $domain->domain) !== 0) {
                return false;
            }
        }
        if ($this->verification_source === 'domain') {
            return $this->sending_domain_id !== null;
        }
        if ($this->verification_source === 'provider') {
            return $this->status === 'verified' && $this->verified_at !== null;
        }

        return $this->verified_at !== null;
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function verified(Builder $query): void
    {
        $sqlite = $query->getConnection() instanceof SQLiteConnection;
        $query->where(function (Builder $query) use ($sqlite): void {
            $query->whereNull('sending_domain_id')->orWhereHas('sendingDomain', function (Builder $domain) use ($sqlite): void {
                $domain->getQuery()->where('status', 'verified')->whereNotNull('verified_at')
                    ->whereColumn(ManagedConfiguration::table('sending_domains').'.owner_id', ManagedConfiguration::table('sender_identities').'.owner_id')
                    ->whereColumn(ManagedConfiguration::table('sending_domains').'.provider_account_id', ManagedConfiguration::table('sender_identities').'.provider_account_id')
                    ->whereRaw(new SenderDomainMatchExpression($sqlite), ['provider', '@']);
            });
        })->where(function (Builder $query): void {
            $query->where(function (Builder $query): void {
                $query->where('verification_source', 'domain')->whereNotNull('sending_domain_id');
            })->orWhere(function (Builder $query): void {
                $query->where('verification_source', 'provider')->where('status', 'verified')->whereNotNull('verified_at');
            })->orWhere(function (Builder $query): void {
                $query->whereNotIn('verification_source', ['domain', 'provider'])->whereNotNull('verified_at');
            });
        });
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function matchingSearch(Builder $query, string $search): void
    {
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $fold = $query->getConnection() instanceof SQLiteConnection ? 'mail_casefold' : 'LOWER';
            $query->where(function (Builder $query) use ($pattern, $fold): void {
                $query->whereRaw("{$fold}(email) LIKE {$fold}(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("{$fold}(name) LIKE {$fold}(?) ESCAPE '!'", [$pattern])
                    ->orWhereHas('sendingDomain', fn (Builder $domain) => $domain->whereRaw("{$fold}(domain) LIKE {$fold}(?) ESCAPE '!'", [$pattern]));
            });
        }
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function verification(Builder $query, string $verification): void
    {
        if ($verification === 'verified') {
            $query->verified();
        } elseif ($verification === 'unverified') {
            $query->whereNot(fn (Builder $query) => $query->verified());
        } elseif ($verification === 'missing') {
            $query->where('status', 'missing');
        }
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
     * The provider account associated with this record.
     *
     * @return BelongsTo<ProviderAccount, $this>
     */
    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    protected static function newFactory(): SenderIdentityFactory
    {
        return SenderIdentityFactory::new();
    }
}
