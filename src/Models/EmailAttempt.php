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
use Nvl\MailNotifications\Database\Factories\EmailAttemptFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * One provider submission attempt, including lease outcome and reconciliation identifiers.
 *
 * @property array<string, mixed>|null $response
 * @property list<string>|null $remote_message_ids
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string $id
 * @property string $email_message_id
 * @property string $provider_account_id
 * @property int $number
 * @property string $status
 * @property string|null $provider_message_id
 * @property bool $retryable
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read email_message_id
 * @nvl-consumer-read provider_account_id
 * @nvl-consumer-read number
 * @nvl-consumer-read status
 * @nvl-consumer-read retryable
 * @nvl-consumer-read error_code
 * @nvl-consumer-read started_at
 * @nvl-consumer-read completed_at
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['email_message_id', 'provider_account_id', 'number', 'status', 'provider_message_id', 'remote_message_ids', 'retryable', 'response', 'error_code', 'error_message', 'started_at', 'completed_at'])]
class EmailAttempt extends Model
{
    public const string TABLE = MailNotificationsTables::EmailAttempts;

    /** @use HasFactory<EmailAttemptFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response' => 'array',
            'remote_message_ids' => 'array',
            'retryable' => 'boolean',
            'number' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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
     * The provider account associated with this record.
     *
     * @return BelongsTo<ProviderAccount, $this>
     */
    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    protected static function newFactory(): EmailAttemptFactory
    {
        return EmailAttemptFactory::new();
    }
}
