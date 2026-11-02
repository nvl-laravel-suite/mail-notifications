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
use Nvl\MailNotifications\Database\Factories\WebhookReceiptFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Immutable inbound provider receipt retained for verification, replay detection and reconciliation.
 *
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property string $id
 * @property string $provider_account_id
 * @property string $payload_hash
 * @property string $status
 * @property int $processing_attempts
 * @property string|null $error_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read provider_account_id
 * @nvl-consumer-read status
 * @nvl-consumer-read processing_attempts
 * @nvl-consumer-read verified_at
 * @nvl-consumer-read processed_at
 * @nvl-consumer-read next_attempt_at
 * @nvl-consumer-read error_code
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['provider_account_id', 'payload_hash', 'payload', 'status', 'processing_attempts', 'next_attempt_at', 'verified_at', 'processed_at', 'error_code'])]
class WebhookReceipt extends Model
{
    public const string TABLE = MailNotificationsTables::WebhookReceipts;

    /** @use HasFactory<WebhookReceiptFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    protected $hidden = ['payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'processing_attempts' => 'integer',
            'verified_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
        ];
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

    protected static function newFactory(): WebhookReceiptFactory
    {
        return WebhookReceiptFactory::new();
    }
}
