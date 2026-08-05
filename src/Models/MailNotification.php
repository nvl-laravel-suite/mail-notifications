<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\MailNotificationFactory;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Laravel\Casts\SensitiveArrayCast;
use Nvl\MailNotifications\Laravel\Casts\UtcImmutableDateTimeCast;
use Nvl\MailNotifications\Support\DatabaseTimestamp;

/**
 * Persists the provider-neutral lifecycle of one outbound mail delivery.
 *
 * @property string $id
 * @property string $correlation_id
 * @property string|null $queue_reference
 * @property string $mailer
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property MailDeliveryStatus $status
 * @property string $message_category
 * @property string|null $subject
 * @property string|null $from_email
 * @property string|null $from_name
 * @property list<array{email: string, name: string|null}> $to_recipients
 * @property list<array{email: string, name: string|null}>|null $cc_recipients
 * @property list<array{email: string, name: string|null}>|null $bcc_recipients
 * @property string|null $primary_recipient_email
 * @property string|null $notifiable_type
 * @property string|null $notifiable_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $status_changed_at
 * @property CarbonImmutable|null $provider_occurred_at
 * @property CarbonImmutable|null $redacted_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MailNotification extends Model
{
    /** @use HasFactory<MailNotificationFactory> */
    use HasFactory;

    use HasUuids;

    public const string TABLE = 'mail_notifications';

    /**
     * Preserve the microsecond precision declared by the package schema.
     *
     * @var string
     */
    protected $dateFormat = DatabaseTimestamp::FORMAT;

    /**
     * Mirror database defaults for new in-memory models.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => MailDeliveryStatus::Pending->value,
    ];

    /**
     * The attributes that may be persisted through lifecycle services.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'correlation_id',
        'queue_reference',
        'mailer',
        'provider',
        'provider_message_id',
        'status',
        'message_category',
        'subject',
        'from_email',
        'from_name',
        'to_recipients',
        'cc_recipients',
        'bcc_recipients',
        'primary_recipient_email',
        'notifiable_type',
        'notifiable_id',
        'metadata',
        'accepted_at',
        'delivered_at',
        'failed_at',
        'status_changed_at',
        'provider_occurred_at',
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
            'mail-notifications.storage.tables.notifications',
            self::TABLE,
        );

        return is_string($table) && $table !== ''
            ? $table
            : self::TABLE;
    }

    /**
     * Relate provider events processed for this notification.
     *
     * @return HasMany<MailNotificationEvent, $this>
     */
    public function providerEvents(): HasMany
    {
        return $this->hasMany(MailNotificationEvent::class);
    }

    /**
     * Define persisted attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MailDeliveryStatus::class,
            'to_recipients' => SensitiveArrayCast::class
                .':notification.to_recipients',
            'cc_recipients' => SensitiveArrayCast::class
                .':notification.cc_recipients',
            'bcc_recipients' => SensitiveArrayCast::class
                .':notification.bcc_recipients',
            'metadata' => SensitiveArrayCast::class
                .':notification.metadata',
            'accepted_at' => UtcImmutableDateTimeCast::class,
            'delivered_at' => UtcImmutableDateTimeCast::class,
            'failed_at' => UtcImmutableDateTimeCast::class,
            'status_changed_at' => UtcImmutableDateTimeCast::class,
            'provider_occurred_at' => UtcImmutableDateTimeCast::class,
            'redacted_at' => UtcImmutableDateTimeCast::class,
            'created_at' => UtcImmutableDateTimeCast::class,
            'updated_at' => UtcImmutableDateTimeCast::class,
        ];
    }

    /**
     * Create a new package-owned model factory.
     */
    protected static function newFactory(): MailNotificationFactory
    {
        return MailNotificationFactory::new();
    }
}
