<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\OwnerMailSettingsFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\AccountStatus;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Owner-wide provider, sender and reply-to defaults inherited by its clients.
 *
 * @property string|null $from_email
 * @property string|null $from_name
 * @property string|null $reply_to_email
 * @property string|null $reply_to_name
 * @property AccountStatus $status
 * @property string $owner_id
 * @property string|null $default_provider_account_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read owner_id
 * @nvl-consumer-read status
 * @nvl-consumer-read default_provider_account_id
 * @nvl-consumer-read from_email
 * @nvl-consumer-read from_name
 * @nvl-consumer-read reply_to_email
 * @nvl-consumer-read reply_to_name
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['status', 'default_provider_account_id', 'from_email', 'from_name', 'reply_to_email', 'reply_to_name'])]
class OwnerMailSettings extends Model
{
    public const string TABLE = MailNotificationsTables::OwnerMailSettings;

    /** @use HasFactory<OwnerMailSettingsFactory> */
    use HasFactory;

    use UsesManagedStorage;

    protected $primaryKey = 'owner_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = ['status' => 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => AccountStatus::class];
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
     * The owner's default sending account.
     *
     * @return BelongsTo<ProviderAccount, $this>
     */
    public function defaultProviderAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'default_provider_account_id');
    }

    protected static function newFactory(): OwnerMailSettingsFactory
    {
        return OwnerMailSettingsFactory::new();
    }
}
