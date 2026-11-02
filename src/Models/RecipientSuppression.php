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
use Nvl\MailNotifications\Database\Factories\RecipientSuppressionFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Provider-level delivery block kept separate from client marketing preferences.
 *
 * @property CarbonImmutable|null $released_at
 * @property string $id
 * @property string $provider_account_id
 * @property string $email
 * @property string $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read provider_account_id
 * @nvl-consumer-read email
 * @nvl-consumer-read reason
 * @nvl-consumer-read released_at
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['provider_account_id', 'email', 'reason', 'released_at'])]
class RecipientSuppression extends Model
{
    public const string TABLE = MailNotificationsTables::RecipientSuppressions;

    /** @use HasFactory<RecipientSuppressionFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'released_at' => 'immutable_datetime',
        ];
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
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

    protected static function newFactory(): RecipientSuppressionFactory
    {
        return RecipientSuppressionFactory::new();
    }
}
