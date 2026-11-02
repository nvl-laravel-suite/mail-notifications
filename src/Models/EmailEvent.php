<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\EmailEventFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Normalized provider activity associated with a message, recipient and verified receipt.
 *
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $occurred_at
 * @property string $id
 * @property string $provider_account_id
 * @property string $email_message_id
 * @property string $email_recipient_id
 * @property string|null $webhook_receipt_id
 * @property string $provider_event_id
 * @property string $type
 * @property Carbon $created_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read provider_account_id
 * @nvl-consumer-read email_message_id
 * @nvl-consumer-read email_recipient_id
 * @nvl-consumer-read type
 * @nvl-consumer-read occurred_at
 * @nvl-consumer-read created_at
 */
#[Fillable(['provider_account_id', 'email_message_id', 'email_recipient_id', 'webhook_receipt_id', 'provider_event_id', 'type', 'occurred_at', 'metadata'])]
class EmailEvent extends Model
{
    public const string TABLE = MailNotificationsTables::EmailEvents;

    /** @use HasFactory<EmailEventFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
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
     * The envelope recipient associated with this normalized observation.
     *
     * @return BelongsTo<EmailRecipient, $this>
     */
    public function emailRecipient(): BelongsTo
    {
        return $this->belongsTo(EmailRecipient::class);
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
     * The verified inbound receipt supporting this event.
     *
     * @return BelongsTo<WebhookReceipt, $this>
     */
    public function webhookReceipt(): BelongsTo
    {
        return $this->belongsTo(WebhookReceipt::class);
    }

    protected static function newFactory(): EmailEventFactory
    {
        return EmailEventFactory::new();
    }
}
