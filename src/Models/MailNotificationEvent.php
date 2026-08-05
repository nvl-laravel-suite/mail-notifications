<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\MailNotificationEventFactory;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Laravel\Casts\SensitiveArrayCast;
use Nvl\MailNotifications\Laravel\Casts\UtcImmutableDateTimeCast;
use Nvl\MailNotifications\Support\DatabaseTimestamp;

/**
 * Persists one authenticated provider event for durable idempotency.
 *
 * @property string $id
 * @property string $mail_notification_id
 * @property string $provider
 * @property string $provider_event_id
 * @property string|null $provider_message_id
 * @property MailDeliveryStatus $normalized_type
 * @property CarbonImmutable $occurred_at
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $processed_at
 * @property CarbonImmutable|null $redacted_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MailNotificationEvent extends Model
{
    /** @use HasFactory<MailNotificationEventFactory> */
    use HasFactory;

    use HasUuids;

    public const string TABLE = 'mail_notification_events';

    /**
     * Preserve the microsecond precision declared by the package schema.
     *
     * @var string
     */
    protected $dateFormat = DatabaseTimestamp::FORMAT;

    /**
     * The attributes that may be persisted through lifecycle services.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'mail_notification_id',
        'provider',
        'provider_event_id',
        'provider_message_id',
        'normalized_type',
        'occurred_at',
        'metadata',
        'processed_at',
        'redacted_at',
    ];

    /**
     * Generate automatic model timestamps independently from the host timezone.
     */
    public function freshTimestamp(): Carbon
    {
        return Carbon::now('UTC');
    }

    /**
     * Resolve the configured package database connection.
     */
    public function getConnectionName(): ?string
    {
        $configuredConnection = config('mail-notifications.storage.connection');

        return is_string($configuredConnection) && $configuredConnection !== ''
            ? $configuredConnection
            : parent::getConnectionName();
    }

    /**
     * Resolve the configured package table.
     */
    public function getTable(): string
    {
        $table = config(
            'mail-notifications.storage.tables.events',
            self::TABLE,
        );

        return is_string($table) && $table !== ''
            ? $table
            : self::TABLE;
    }

    /**
     * Relate the provider event to its tracked notification.
     *
     * @return BelongsTo<MailNotification, $this>
     */
    public function mailNotification(): BelongsTo
    {
        return $this->belongsTo(MailNotification::class);
    }

    /**
     * Define persisted attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'normalized_type' => MailDeliveryStatus::class,
            'occurred_at' => UtcImmutableDateTimeCast::class,
            'metadata' => SensitiveArrayCast::class
                .':provider_event.metadata',
            'processed_at' => UtcImmutableDateTimeCast::class,
            'redacted_at' => UtcImmutableDateTimeCast::class,
            'created_at' => UtcImmutableDateTimeCast::class,
            'updated_at' => UtcImmutableDateTimeCast::class,
        ];
    }

    /**
     * Create a new package-owned model factory.
     */
    protected static function newFactory(): MailNotificationEventFactory
    {
        return MailNotificationEventFactory::new();
    }
}
