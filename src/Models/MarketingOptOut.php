<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\MarketingOptOutFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Client-scoped marketing preference; it does not suppress transactional delivery.
 *
 * @property CarbonImmutable|null $released_at
 * @property string $id
 * @property string $client_id
 * @property string $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read client_id
 * @nvl-consumer-read email
 * @nvl-consumer-read released_at
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['client_id', 'email', 'released_at'])]
class MarketingOptOut extends Model
{
    public const string TABLE = MailNotificationsTables::MarketingOptOuts;

    /** @use HasFactory<MarketingOptOutFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['released_at' => 'immutable_datetime'];
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
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

    protected static function newFactory(): MarketingOptOutFactory
    {
        return MarketingOptOutFactory::new();
    }
}
