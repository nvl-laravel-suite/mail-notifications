<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\AssetFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\MailMediaSlots;
use Nvl\MailNotifications\Support\UsesManagedStorage;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Traits\InteractsWithNvlMedia;

/** Client-owned reusable file catalogue with native Media storage and retained byte metadata.
 * @property string|null $media_id
 * @property-read Media|null $media
 * @property string $id
 * @property string $client_id
 * @property string $name
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property string $checksum
 * @property bool $is_public
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read client_id
 * @nvl-consumer-read name
 * @nvl-consumer-read mime_type
 * @nvl-consumer-read size
 * @nvl-consumer-read checksum
 * @nvl-consumer-read is_public
 * @nvl-consumer-read archived_at
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['media_id', 'client_id', 'name', 'disk', 'path', 'mime_type', 'size', 'checksum', 'is_public', 'archived_at'])]
#[Hidden(['media_id'])]
class Asset extends Model implements HasNvlMedia
{
    public const string TABLE = MailNotificationsTables::Assets;

    /** @use HasFactory<AssetFactory> */
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
            'is_public' => 'boolean',
            'archived_at' => 'datetime',
        ];
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

    /** Register one exclusive original-byte slot; active Ownership still requires the host's native anchor. */
    public function registerNvlMedia(MediaRegistration $media, ?Media $asset = null): void
    {
        MailMediaSlots::register($media, $this->is_public);
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

    protected static function newFactory(): AssetFactory
    {
        return AssetFactory::new();
    }
}
