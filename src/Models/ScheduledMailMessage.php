<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\ScheduledMailMessageFactory;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Laravel\Casts\SensitiveArrayCast;
use Nvl\MailNotifications\Laravel\Casts\UtcImmutableDateTimeCast;
use Nvl\MailNotifications\Support\DatabaseTimestamp;

/**
 * Persists one provider-neutral scheduled outbound mail message.
 *
 * @property string $id
 * @property string $factory_alias
 * @property int $payload_version
 * @property array<string, mixed> $payload
 * @property list<array{email: string, name: string|null}> $to_recipients
 * @property list<array{email: string, name: string|null}>|null $cc_recipients
 * @property list<array{email: string, name: string|null}>|null $bcc_recipients
 * @property ScheduledMailStatus $status
 * @property CarbonImmutable $scheduled_for
 * @property CarbonImmutable $available_at
 * @property int $attempts
 * @property int $max_attempts
 * @property CarbonImmutable|null $last_attempt_at
 * @property string|null $claim_token
 * @property CarbonImmutable|null $locked_until
 * @property string|null $last_error
 * @property string|null $notifiable_type
 * @property string|null $notifiable_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $redacted_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ScheduledMailMessage extends Model
{
    /** @use HasFactory<ScheduledMailMessageFactory> */
    use HasFactory;

    use HasUuids;

    public const string TABLE = 'scheduled_mail_messages';

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
        'status' => ScheduledMailStatus::Pending->value,
        'attempts' => 0,
        'max_attempts' => 3,
    ];

    /**
     * Keep internal fencing data out of serialized host output.
     *
     * @var list<string>
     */
    protected $hidden = [
        'claim_token',
    ];

    /**
     * Attributes owned by scheduling lifecycle services.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'factory_alias',
        'payload_version',
        'payload',
        'to_recipients',
        'cc_recipients',
        'bcc_recipients',
        'status',
        'scheduled_for',
        'available_at',
        'attempts',
        'max_attempts',
        'last_attempt_at',
        'claim_token',
        'locked_until',
        'last_error',
        'notifiable_type',
        'notifiable_id',
        'metadata',
        'sent_at',
        'failed_at',
        'cancelled_at',
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

        return is_string($configuredConnection)
            && trim($configuredConnection) !== ''
                ? trim($configuredConnection)
                : parent::getConnectionName();
    }

    /**
     * Resolve the configured scheduled-message table.
     */
    public function getTable(): string
    {
        $table = config(
            'mail-notifications.storage.tables.scheduled_messages',
            self::TABLE,
        );

        return is_string($table) && trim($table) !== ''
            ? trim($table)
            : self::TABLE;
    }

    /**
     * Define persisted attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_version' => 'integer',
            'payload' => SensitiveArrayCast::class
                .':scheduled_message.payload',
            'to_recipients' => SensitiveArrayCast::class
                .':scheduled_message.to_recipients',
            'cc_recipients' => SensitiveArrayCast::class
                .':scheduled_message.cc_recipients',
            'bcc_recipients' => SensitiveArrayCast::class
                .':scheduled_message.bcc_recipients',
            'status' => ScheduledMailStatus::class,
            'scheduled_for' => UtcImmutableDateTimeCast::class,
            'available_at' => UtcImmutableDateTimeCast::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'last_attempt_at' => UtcImmutableDateTimeCast::class,
            'locked_until' => UtcImmutableDateTimeCast::class,
            'metadata' => SensitiveArrayCast::class
                .':scheduled_message.metadata',
            'sent_at' => UtcImmutableDateTimeCast::class,
            'failed_at' => UtcImmutableDateTimeCast::class,
            'cancelled_at' => UtcImmutableDateTimeCast::class,
            'redacted_at' => UtcImmutableDateTimeCast::class,
            'created_at' => UtcImmutableDateTimeCast::class,
            'updated_at' => UtcImmutableDateTimeCast::class,
        ];
    }

    /**
     * Create a new package-owned model factory.
     */
    protected static function newFactory(): ScheduledMailMessageFactory
    {
        return ScheduledMailMessageFactory::new();
    }
}
