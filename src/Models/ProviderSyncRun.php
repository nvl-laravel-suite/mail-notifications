<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\ProviderSyncRunFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\ProviderSyncStatus;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Durable paginated account synchronization progress with retry and stale-worker fencing.
 *
 * @property ProviderSyncStatus $status
 * @property CarbonImmutable|null $next_retry_at
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string $id
 * @property string $provider_account_id
 * @property string|null $active_provider_account_id
 * @property string $phase
 * @property string $domains_status
 * @property string $senders_status
 * @property int $pages_processed
 * @property int $domains_processed
 * @property int $senders_processed
 * @property int|null $domains_total
 * @property int|null $senders_total
 * @property int $retry_count
 * @property int $page_attempts
 * @property int $checkpoint
 * @property string|null $cursor
 * @property string $queue_connection
 * @property string $configuration_fingerprint
 * @property string|null $lease_token
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read provider_account_id
 * @nvl-consumer-read status
 * @nvl-consumer-read phase
 * @nvl-consumer-read domains_status
 * @nvl-consumer-read senders_status
 * @nvl-consumer-read pages_processed
 * @nvl-consumer-read domains_processed
 * @nvl-consumer-read senders_processed
 * @nvl-consumer-read domains_total
 * @nvl-consumer-read senders_total
 * @nvl-consumer-read retry_count
 * @nvl-consumer-read next_retry_at
 * @nvl-consumer-read started_at
 * @nvl-consumer-read finished_at
 * @nvl-consumer-read error_code
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['provider_account_id', 'active_provider_account_id', 'status', 'phase', 'domains_status', 'senders_status', 'pages_processed', 'domains_processed', 'senders_processed', 'domains_total', 'senders_total', 'retry_count', 'page_attempts', 'checkpoint', 'cursor', 'queue_connection', 'configuration_fingerprint', 'lease_token', 'lease_until', 'dispatched_at', 'next_retry_at', 'started_at', 'finished_at', 'error_code', 'error_message'])]
#[Hidden(['active_provider_account_id', 'checkpoint', 'cursor', 'queue_connection', 'configuration_fingerprint', 'lease_token', 'lease_until', 'dispatched_at'])]
class ProviderSyncRun extends Model
{
    public const string TABLE = MailNotificationsTables::ProviderSyncRuns;

    /** @use HasFactory<ProviderSyncRunFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => ProviderSyncStatus::class, 'next_retry_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'pages_processed' => 'integer', 'domains_processed' => 'integer', 'senders_processed' => 'integer', 'domains_total' => 'integer', 'senders_total' => 'integer', 'retry_count' => 'integer', 'page_attempts' => 'integer', 'checkpoint' => 'integer'];
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

    protected static function newFactory(): ProviderSyncRunFactory
    {
        return ProviderSyncRunFactory::new();
    }
}
