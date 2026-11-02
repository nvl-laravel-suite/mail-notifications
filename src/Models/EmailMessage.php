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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\EmailMessageFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\EmailMessageType;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Accepted client-scoped mail intent with original content snapshots and delivery history.
 *
 * @property EmailMessageType $type
 * @property string|null $category
 * @property string|null $language
 * @property array<string, mixed>|null $data
 * @property array<string, mixed>|null $metadata
 * @property array{original_recipients: array<string, list<array{email: string, name?: string|null}>>, effective_recipient: array{email: string, name: string|null}}|null $testing_interception
 * @property array<array-key, string>|null $tags
 * @property CarbonImmutable|null $send_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $completed_at
 * @property string $id
 * @property string $client_id
 * @property string $provider_account_id
 * @property string|null $email_template_version_id
 * @property string $sender_identity_id
 * @property string $status
 * @property string $idempotency_key
 * @property string $request_hash
 * @property string $from_email
 * @property string|null $from_name
 * @property string|null $reply_to_email
 * @property string|null $reply_to_name
 * @property string $subject
 * @property string|null $html
 * @property string|null $text
 * @property string|null $external_reference
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $original_subject
 * @property string|null $original_html
 * @property string|null $original_text
 * @property string|null $composition_revision_id
 * @property string|null $composition_render_id
 * @property string|null $original_css
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read client_id
 * @nvl-consumer-read provider_account_id
 * @nvl-consumer-read sender_identity_id
 * @nvl-consumer-read type
 * @nvl-consumer-read category
 * @nvl-consumer-read language
 * @nvl-consumer-read status
 * @nvl-consumer-read subject
 * @nvl-consumer-read external_reference
 * @nvl-consumer-read send_at
 * @nvl-consumer-read next_attempt_at
 * @nvl-consumer-read submitted_at
 * @nvl-consumer-read cancelled_at
 * @nvl-consumer-read completed_at
 * @nvl-consumer-read error_code
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['type', 'category', 'language', 'composition_revision_id', 'composition_render_id', 'client_id', 'provider_account_id', 'email_template_version_id', 'sender_identity_id', 'status', 'idempotency_key', 'request_hash', 'from_email', 'from_name', 'reply_to_email', 'reply_to_name', 'subject', 'html', 'text', 'original_subject', 'original_html', 'original_text', 'original_css', 'data', 'metadata', 'testing_interception', 'tags', 'external_reference', 'send_at', 'next_attempt_at', 'dispatched_at', 'lease_until', 'submitted_at', 'cancelled_at', 'completed_at', 'error_code', 'error_message'])]
class EmailMessage extends Model
{
    public const string TABLE = MailNotificationsTables::EmailMessages;

    /** @use HasFactory<EmailMessageFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => EmailMessageType::class,
            'data' => 'array',
            'metadata' => 'array',
            'testing_interception' => 'array',
            'tags' => 'array',
            'send_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return Attribute<string|null, string|null> */
    protected function language(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value === null ? null : mb_strtolower($value));
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
     * The persisted To, Cc and Bcc envelope recipients.
     *
     * @return HasMany<EmailRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(EmailRecipient::class);
    }

    /**
     * Client-scoped business references associated with this message.
     *
     * @return HasMany<EmailMessageReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(EmailMessageReference::class)->orderBy('type')->orderBy('reference_id');
    }

    /**
     * Recorded provider submission attempts in lifecycle order.
     *
     * @return HasMany<EmailAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(EmailAttempt::class);
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

    /**
     * Durable attachment evidence, independent of later byte expiry.
     *
     * @return HasMany<MessageAsset, $this>
     */
    public function messageAssets(): HasMany
    {
        return $this->hasMany(MessageAsset::class);
    }

    /**
     * Saved rule matches and labels associated with this message.
     *
     * @return HasMany<EmailMessageFlag, $this>
     */
    public function flags(): HasMany
    {
        return $this->hasMany(EmailMessageFlag::class);
    }

    protected static function newFactory(): EmailMessageFactory
    {
        return EmailMessageFactory::new();
    }
}
