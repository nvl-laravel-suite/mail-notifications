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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Nvl\MailNotifications\Database\Factories\SendingDomainFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/** Managed mail persistence model.
 * @property string $id
 * @property string $owner_id
 * @property string $provider_account_id
 * @property string|null $remote_id
 * @property string $domain
 * @property string $status
 * @property string $verification_source
 * @property array<array-key, mixed>|null $dns_records
 * @property CarbonImmutable|null $verified_at
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
 * @nvl-consumer-read domain
 * @nvl-consumer-read status
 * @nvl-consumer-read verification_source
 * @nvl-consumer-read verified_at
 * @nvl-consumer-read last_synced_at
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['owner_id', 'provider_account_id', 'remote_id', 'domain', 'status', 'verification_source', 'dns_records', 'verified_at', 'last_synced_at'])]
class SendingDomain extends Model
{
    public const string TABLE = MailNotificationsTables::SendingDomains;

    /** @use HasFactory<SendingDomainFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['dns_records' => 'array', 'verified_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime'];
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
     * Determine whether this domain is verified under the persisted provider status.
     */
    public function isVerified(): bool
    {
        return $this->status === 'verified' && $this->verified_at !== null;
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function verified(Builder $query): void
    {
        $query->where('status', 'verified')->whereNotNull('verified_at');
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function matchingSearch(Builder $query, string $search): void
    {
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $column = $query->getQuery()->getGrammar()->wrap($this->qualifyColumn('domain'));
            $fold = $query->getConnection() instanceof SQLiteConnection ? 'mail_casefold' : 'LOWER';
            $sql = "{$fold}({$column}) LIKE {$fold}(?) ESCAPE '!'";
            $this->assertSafeSearchSql($sql);
            $query->whereRaw($sql, [$pattern]);
        }
    }

    /**
     * @phpstan-assert literal-string $sql
     */
    private function assertSafeSearchSql(string $sql): void
    {
        if (preg_match("/^(?:mail_casefold|LOWER)\\([A-Za-z0-9_.\"`\\[\\]]+\\) LIKE (?:mail_casefold|LOWER)\\(\\?\\) ESCAPE '!'$/D", $sql) !== 1) {
            throw new InvalidArgumentException('Sending domain search produced unsafe SQL.');
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

    protected static function newFactory(): SendingDomainFactory
    {
        return SendingDomainFactory::new();
    }
}
