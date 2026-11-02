<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaOwnerSlotOperation;
use Nvl\Media\Services\MediaAssetService;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaUploader;
use RuntimeException;
use Throwable;

/** Composes native Media operations for a host-authorized mail owner or trusted queued intent.
 * @internal
 */
class MailMediaStorage
{
    public function __construct(private MediaUploader $uploader, private MediaAttachments $attachments, private MediaLibrary $library, private MediaAssetService $assets, private MediaAuthorization $authorization) {}

    /**
     * Require mail and native Media writes to share the supported transaction connection.
     */
    public function assertConnection(Model $owner): void
    {
        $model = config('nvl-media.models.media', Media::class);
        if (! is_string($model) || ! is_a($model, Media::class, true)) {
            throw new RuntimeException('The configured Media model is invalid.');
        }
        if ($owner->getConnection() !== (new $model)->getConnection() || $owner->getConnection() !== (new MediaOwnerSlotOperation)->getConnection()) {
            throw new RuntimeException('Managed mail and Media must use the same actual database connection.');
        }
    }

    /**
     * Persist original bytes in the declared owner slot using native Media's authorization and storage operation.
     */
    public function store(Asset|MessageAsset $owner, UploadedFile $file): Media
    {
        $this->assertConnection($owner);

        return $owner->getConnection()->transaction(function () use ($owner, $file): Media {
            $actor = $this->actor($owner);
            $slot = MediaRegistration::forOwner($owner)->getSlot('bytes') ?? throw new RuntimeException('The mail bytes slot is not registered.');
            try {
                $media = $this->uploader->upload(actor: $actor, file: $file, disk: $slot->resolveDisk(false), model: $owner, slot: $slot, fileName: $owner->name, isPublic: false, allowDuplicates: true, deduplicateExisting: false, skipAutoVariations: true);
                $this->attachments->attach(actor: $actor, media: $media, model: $owner, collection: 'bytes', dispatchVariations: false, slot: $slot);
            } catch (FileUnacceptableForCollection $exception) {
                throw ValidationException::withMessages(['attachments' => $exception->getMessage()]);
            }
            $this->synchronize($owner, $media);

            return $media;
        });
    }

    /**
     * Copy retained original bytes into the target mail owner's exclusive native Media slot.
     */
    public function copy(Asset $source, MessageAsset $destination): Media
    {
        $this->assertConnection($source);
        $this->assertConnection($destination);
        $actor = $this->actor($destination);
        if ($source->client_id !== $actor->id || $source->archived_at !== null || $source->media_id === null) {
            throw ValidationException::withMessages(['attachments' => 'Every catalog asset must be active and belong to this client.']);
        }
        $sourceMedia = $this->attachments->getSlot($actor, $source, 'bytes');
        if ($sourceMedia === null || $sourceMedia->id !== $source->media_id) {
            throw new RuntimeException('The catalog Media association is unavailable.');
        }
        try {
            $media = $this->attachments->copySlot($actor, $destination, 'bytes', $sourceMedia->id);
        } catch (FileUnacceptableForCollection $exception) {
            throw ValidationException::withMessages(['attachments' => $exception->getMessage()]);
        }
        $this->synchronize($destination, $media);

        return $media;
    }

    /**
     * Resolve the configured Media model for this mail owner without substituting cross-owner bytes.
     *
     * @return array{disk: string, path: string, mime_type: string, etag: string}
     */
    public function resolve(Asset|MessageAsset $owner): array
    {
        if ($owner->media_id === null) {
            return ['disk' => $owner->disk, 'path' => $owner->path, 'mime_type' => $owner->mime_type, 'etag' => $owner->checksum];
        }
        $this->assertConnection($owner);
        $actor = $this->actor($owner);
        $media = $this->attachments->getSlot($actor, $owner, 'bytes');
        if ($media === null || $media->id !== $owner->media_id) {
            throw new RuntimeException('The mail Media association is unavailable.');
        }
        if (! $this->authorization->allows($actor, MediaAbility::Download, $media, $owner)) {
            throw new AuthorizationException('The client is not authorized to read these Media bytes.');
        }

        return $this->assets->resolve($media);
    }

    /**
     * Read bounded original bytes through the mail owner's declared native Media capability.
     */
    public function bytes(Asset|MessageAsset $owner): string
    {
        $location = $this->resolve($owner);
        $bytes = Storage::disk($location['disk'])->get($location['path']);
        if ($bytes === null) {
            throw new RuntimeException('The mail attachment bytes are unavailable.');
        }

        return $bytes;
    }

    /**
     * Open the retained original-byte stream for the caller's explicitly scoped mail owner.
     *
     * @return resource
     */
    public function readStream(Asset|MessageAsset $owner)
    {
        $location = $this->resolve($owner);
        $stream = Storage::disk($location['disk'])->readStream($location['path']);
        if (! is_resource($stream)) {
            throw new RuntimeException('The mail attachment bytes are unavailable.');
        }

        return $stream;
    }

    /**
     * Check whether the mail owner's retained Media bytes are currently available.
     */
    public function exists(Asset|MessageAsset $owner): bool
    {
        try {
            $location = $this->resolve($owner);

            return Storage::disk($location['disk'])->exists($location['path']);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Relocate original mail bytes through the native Media lifecycle, preserving the owner declaration.
     */
    public function relocate(Asset $owner, string $disk, bool $public): void
    {
        $this->assertConnection($owner);
        if ($owner->media_id === null) {
            throw new RuntimeException('Legacy catalog assets must be ingested before relocation.');
        }
        $media = $this->attachments->getSlot($this->actor($owner), $owner, 'bytes');
        if ($media === null || $media->id !== $owner->media_id) {
            throw new RuntimeException('The catalog Media association is unavailable.');
        }
        $relocated = $this->library->relocate($this->actor($owner), $media, $disk, $public ? MediaVisibility::Public : MediaVisibility::Private, $media->revision);
        $this->synchronize($owner, $relocated);
    }

    /** Returns true only after native deletion and explicit physical absence confirmation. */
    public function deleteConfirmed(Asset|MessageAsset $owner): bool
    {
        $this->assertConnection($owner);
        if ($owner->media_id === null || $owner->getConnection()->transactionLevel() !== 0) {
            throw new RuntimeException('Media deletion confirmation requires a native asset outside an outer transaction.');
        }
        $actor = $this->actor($owner);
        try {
            $this->library->delete($actor, $owner->media_id, owner: $owner);
        } catch (ModelNotFoundException) {
            // A previous retention run may already have committed the tombstone.
        }

        return $this->library->purgeDeletedFiles($actor, $owner->media_id, $owner);
    }

    private function actor(Asset|MessageAsset $owner): Client
    {
        if (! $owner->exists) {
            throw new RuntimeException('Media requires a persisted mail owner.');
        }
        $client = $owner instanceof Asset ? $owner->client()->first() : $owner->emailMessage()->first()?->client()->first();
        if (! $client instanceof Client || ! $client->exists) {
            throw new RuntimeException('Media requires the persisted mail client actor.');
        }

        return $client;
    }

    private function synchronize(Asset|MessageAsset $owner, Media $media): void
    {
        $owner->forceFill(['media_id' => $media->id, 'disk' => $media->disk, 'path' => $media->buildPath(), 'mime_type' => $media->mime_type, 'size' => $media->size, 'checksum' => $media->digest])->save();
        $owner->unsetRelation('media');
    }
}
