<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\EmailRecipientFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * One To, Cc or Bcc envelope recipient and its independently projected delivery state.
 *
 * @property CarbonImmutable|null $delivery_event_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $bounced_at
 * @property CarbonImmutable|null $suppressed_at
 * @property CarbonImmutable|null $complained_at
 * @property CarbonImmutable|null $unsubscribed_at
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $clicked_at
 * @property string $id
 * @property string $email_message_id
 * @property string $type
 * @property string $email
 * @property string|null $name
 * @property string $delivery_status
 * @property int $open_count
 * @property int $click_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read email_message_id
 * @nvl-consumer-read type
 * @nvl-consumer-read email
 * @nvl-consumer-read name
 * @nvl-consumer-read delivery_status
 * @nvl-consumer-read delivery_event_at
 * @nvl-consumer-read delivered_at
 * @nvl-consumer-read bounced_at
 * @nvl-consumer-read suppressed_at
 * @nvl-consumer-read complained_at
 * @nvl-consumer-read unsubscribed_at
 * @nvl-consumer-read opened_at
 * @nvl-consumer-read clicked_at
 * @nvl-consumer-read open_count
 * @nvl-consumer-read click_count
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['email_message_id', 'type', 'email', 'name', 'delivery_status', 'delivery_event_at', 'delivered_at', 'bounced_at', 'suppressed_at', 'complained_at', 'unsubscribed_at', 'opened_at', 'clicked_at', 'open_count', 'click_count'])]
class EmailRecipient extends Model
{
    public const string TABLE = MailNotificationsTables::EmailRecipients;

    /** @use HasFactory<EmailRecipientFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivery_event_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'bounced_at' => 'immutable_datetime',
            'suppressed_at' => 'immutable_datetime',
            'complained_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'clicked_at' => 'immutable_datetime',
            'open_count' => 'integer',
            'click_count' => 'integer',
        ];
    }

    /**
     * The message owning this record.
     *
     * @return BelongsTo<EmailMessage, $this>
     */
    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    /**
     * Normalized delivery events associated with this record.
     *
     * @return HasMany<EmailEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class);
    }

    protected static function newFactory(): EmailRecipientFactory
    {
        return EmailRecipientFactory::new();
    }
}
