<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\MailNotifications\Database\Factories\EmailMessageReferenceFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Client-scoped business reference without a cross-application database relationship.
 *
 * @property string $type
 * @property string $reference_id
 * @property string $id
 * @property string $client_id
 * @property string $email_message_id
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read client_id
 * @nvl-consumer-read email_message_id
 * @nvl-consumer-read type
 * @nvl-consumer-read reference_id
 */
#[Fillable(['client_id', 'email_message_id', 'type', 'reference_id'])]
class EmailMessageReference extends Model
{
    public const string TABLE = MailNotificationsTables::EmailMessageReferences;

    /** @use HasFactory<EmailMessageReferenceFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    public $timestamps = false;

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
     * The consuming client owning this record.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function newFactory(): EmailMessageReferenceFactory
    {
        return EmailMessageReferenceFactory::new();
    }
}
