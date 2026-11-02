<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\StagedMailFile;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use RuntimeException;

/** Composes native media operations for targets already authorized by the host.
 * @internal
 */
class AssetManager
{
    public function __construct(private MailMediaStorage $media) {}

    /**
     * Store client-owned reusable bytes through native Media; callers authorize the client before invoking this helper.
     */
    public function store(Client $client, UploadedFile $file, ?string $name = null): Asset
    {
        $this->media->assertConnection($client);

        return $client->getConnection()->transaction(function () use ($client, $file, $name): Asset {
            $asset = $client->assets()->create(['name' => $name ?? basename($file->getClientOriginalName()), 'disk' => ManagedConfiguration::string('storage.disk', 'local'), 'path' => '', 'mime_type' => $file->getMimeType() ?? '', 'size' => $file->getSize(), 'checksum' => '', 'is_public' => false]);
            $this->media->store($asset, $file);

            return $asset;
        });
    }

    /**
     * Publish eligible asset bytes without transferring their client or native Media ownership.
     */
    public function publish(Asset $asset): Asset
    {
        $this->media->assertConnection($asset);

        return $asset->getConnection()->transaction(function () use ($asset): Asset {
            $asset = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($asset->archived_at !== null) {
                throw (new ModelNotFoundException)->setModel(Asset::class, [$asset->id]);
            }
            if (! in_array($asset->mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                throw ValidationException::withMessages(['asset' => 'Only raster images can be published.']);
            }
            if ($asset->is_public) {
                return $asset;
            }
            $this->ingestLegacy($asset);
            $this->media->relocate($asset, ManagedConfiguration::string('storage.public_disk', 'public'), true);
            $asset->update(['is_public' => true]);

            return $asset;
        });
    }

    /**
     * Archive a reusable asset without erasing bytes still referenced by retained message attachments.
     */
    public function archive(Asset $asset): void
    {
        if ($asset->media_id !== null) {
            $this->media->assertConnection($asset);
        }
        $asset->getConnection()->transaction(function () use ($asset): void {
            $asset = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($asset->media_id !== null && $asset->is_public) {
                $this->media->relocate($asset, ManagedConfiguration::string('storage.disk', 'local'), false);
            }
            $asset->update(['archived_at' => now(), 'is_public' => false]);
        });
    }

    private function ingestLegacy(Asset $asset): void
    {
        if ($asset->media_id !== null) {
            return;
        }
        $stream = $this->media->readStream($asset);
        $lease = tmpfile();
        if (! is_resource($lease)) {
            fclose($stream);
            throw new RuntimeException('The catalog asset could not be prepared for native storage.');
        }
        try {
            $size = stream_copy_to_stream($stream, $lease, AttachmentLocalizer::MAX_FILE_BYTES + 1);
            if ($size !== $asset->size || $size > AttachmentLocalizer::MAX_FILE_BYTES) {
                throw new RuntimeException('The catalog asset bytes no longer match their snapshot.');
            }
            rewind($lease);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $lease);
            if (! hash_equals($asset->checksum, hash_final($hash))) {
                throw new RuntimeException('The catalog asset bytes no longer match their snapshot.');
            }
            rewind($lease);
            $metadata = stream_get_meta_data($lease);
            if (! isset($metadata['uri'])) {
                throw new RuntimeException('The temporary attachment path is unavailable.');
            }
            /** Keep the legacy object's cleanup identity while historical borrowed attachments still reference it. */
            StagedMailFile::query()->firstOrCreate(['disk' => $asset->disk, 'path' => $asset->path], ['client_id' => $asset->client_id]);
            $this->media->store($asset, new UploadedFile($metadata['uri'], $asset->name, $asset->mime_type, null, true));
        } finally {
            fclose($stream);
            fclose($lease);
        }
    }
}
