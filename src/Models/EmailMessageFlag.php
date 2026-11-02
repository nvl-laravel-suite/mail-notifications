<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\EmailMessageFlagFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\EmailFlagType;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Saved message tag retaining the matched rule evidence after subsequent rule changes.
 *
 * @property EmailFlagType $type
 * @property string $id
 * @property string $client_id
 * @property string $email_message_id
 * @property string|null $email_flag_rule_id
 * @property string $label
 * @property string $color
 * @property string $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read client_id
 * @nvl-consumer-read email_message_id
 * @nvl-consumer-read email_flag_rule_id
 * @nvl-consumer-read label
 * @nvl-consumer-read color
 * @nvl-consumer-read type
 * @nvl-consumer-read value
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['client_id', 'email_message_id', 'email_flag_rule_id', 'label', 'color', 'type', 'value'])]
class EmailMessageFlag extends Model
{
    public const string TABLE = MailNotificationsTables::EmailMessageFlags;

    /** @use HasFactory<EmailMessageFlagFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => EmailFlagType::class];
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
     * The message owning this record.
     *
     * @return BelongsTo<EmailMessage, $this>
     */
    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    /**
     * The original rule, when it still exists; the saved match remains independent.
     *
     * @return BelongsTo<EmailFlagRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(EmailFlagRule::class, 'email_flag_rule_id');
    }

    protected static function newFactory(): EmailMessageFlagFactory
    {
        return EmailMessageFlagFactory::new();
    }
}
