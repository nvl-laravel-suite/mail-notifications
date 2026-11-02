<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\ClientMailSettingsFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\AccountStatus;
use Nvl\MailNotifications\Enums\ClientAuthenticationMode;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Client delivery overrides, authentication mode and limits over its owner defaults.
 *
 * @property string|null $from_email
 * @property string|null $from_name
 * @property string|null $reply_to_email
 * @property string|null $reply_to_name
 * @property AccountStatus $status
 * @property ClientAuthenticationMode $authentication_mode
 * @property string $client_id
 * @property string $owner_id
 * @property string|null $provider_account_id
 * @property string|null $sender_identity_id
 * @property int $rate_limit_per_minute
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read client_id
 * @nvl-consumer-read owner_id
 * @nvl-consumer-read status
 * @nvl-consumer-read authentication_mode
 * @nvl-consumer-read provider_account_id
 * @nvl-consumer-read sender_identity_id
 * @nvl-consumer-read from_email
 * @nvl-consumer-read from_name
 * @nvl-consumer-read reply_to_email
 * @nvl-consumer-read reply_to_name
 * @nvl-consumer-read rate_limit_per_minute
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['owner_id', 'status', 'provider_account_id', 'sender_identity_id', 'from_email', 'from_name', 'reply_to_email', 'reply_to_name', 'rate_limit_per_minute', 'authentication_mode'])]
class ClientMailSettings extends Model
{
    public const string TABLE = MailNotificationsTables::ClientMailSettings;

    /** @use HasFactory<ClientMailSettingsFactory> */
    use HasFactory;

    use UsesManagedStorage;

    protected $primaryKey = 'client_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = ['status' => 'active', 'rate_limit_per_minute' => 60, 'authentication_mode' => 'legacy'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => AccountStatus::class, 'authentication_mode' => ClientAuthenticationMode::class, 'rate_limit_per_minute' => 'integer'];
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
     * The provider account associated with this record.
     *
     * @return BelongsTo<ProviderAccount, $this>
     */
    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    /**
     * The client's explicit sender override, when configured.
     *
     * @return BelongsTo<SenderIdentity, $this>
     */
    public function senderIdentity(): BelongsTo
    {
        return $this->belongsTo(SenderIdentity::class);
    }

    protected static function newFactory(): ClientMailSettingsFactory
    {
        return ClientMailSettingsFactory::new();
    }
}
