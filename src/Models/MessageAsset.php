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
use Illuminate\Support\Facades\Storage;
use Nvl\MailNotifications\Database\Factories\MessageAssetFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Services\Delivery\MailMediaStorage;
use Nvl\MailNotifications\Support\MailMediaSlots;
use Nvl\MailNotifications\Support\UsesManagedStorage;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Traits\InteractsWithNvlMedia;

/**
 * Durable attachment evidence whose private bytes may expire independently of message history.
 *
 * @property CarbonImmutable|null $bytes_expires_at
 * @property CarbonImmutable|null $bytes_deleted_at
 * @property string|null $media_id
 * @property-read Media|null $media
 * @property string $id
 * @property string $email_message_id
 * @property string|null $asset_id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string $mime_type
 * @property int $size
 * @property string $checksum
 * @property string $disposition
 * @property string|null $content_id
 * @property string $source_kind
 * @property bool $owns_file
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read email_message_id
 * @nvl-consumer-read asset_id
 * @nvl-consumer-read name
 * @nvl-consumer-read mime_type
 * @nvl-consumer-read size
 * @nvl-consumer-read checksum
 * @nvl-consumer-read disposition
 * @nvl-consumer-read content_id
 * @nvl-consumer-read source_kind
 * @nvl-consumer-read bytes_expires_at
 * @nvl-consumer-read bytes_deleted_at
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['media_id', 'email_message_id', 'asset_id', 'disk', 'path', 'name', 'mime_type', 'size', 'checksum', 'disposition', 'content_id', 'source_kind', 'owns_file', 'bytes_expires_at', 'bytes_deleted_at'])]
#[Hidden(['disk', 'path', 'media_id'])]
class MessageAsset extends Model implements HasNvlMedia
{
    public const string TABLE = MailNotificationsTables::MessageAssets;

    /** @use HasFactory<MessageAssetFactory> */
    use HasFactory;

    use HasUuids;
    use InteractsWithNvlMedia;
    use UsesManagedStorage;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'media_id' => 'string',
            'size' => 'integer',
            'owns_file' => 'boolean',
            'bytes_expires_at' => 'immutable_datetime',
            'bytes_deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * Report current byte availability without deleting the durable attachment evidence.
     */
    public function downloadStatus(): string
    {
        if ($this->bytes_deleted_at !== null || $this->bytes_expires_at?->isPast()) {
            return 'expired';
        }

        if ($this->media_id !== null) {
            return app(MailMediaStorage::class)->exists($this) ? 'available' : 'unavailable';
        }

        return Storage::disk($this->disk)->exists($this->path) ? 'available' : 'unavailable';
    }

    /**
     * Return attachment evidence and current availability without exposing private storage paths.
     *
     * @return array<string, mixed>
     */
    public function durableMetadata(): array
    {
        return $this->only(['id', 'asset_id', 'source_kind', 'name', 'mime_type', 'size', 'checksum', 'disposition', 'content_id', 'bytes_expires_at', 'bytes_deleted_at']) + ['download_status' => $this->downloadStatus()];
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
     * The reusable source asset, when the attachment originated from the catalogue.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** Register one exclusive original-byte slot; active Ownership still requires the host's native anchor. */
    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
    {
        MailMediaSlots::register($media);
    }

    /**
     * The configured native Media record retaining this owner's original bytes.
     *
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(app(MediaModelRegistry::class)->mediaClass(), 'media_id');
    }

    protected static function newFactory(): MessageAssetFactory
    {
        return MessageAssetFactory::new();
    }
}
