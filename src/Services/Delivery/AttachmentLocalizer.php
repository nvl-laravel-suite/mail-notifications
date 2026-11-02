<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Support\MailMediaSlots;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use RuntimeException;
use Throwable;

/**
 * Stages bounded POST, URL and inline bytes before attaching durable evidence to an accepted message.
 *
 * @phpstan-import-type ManagedMessageInput from EmailContent
 * @phpstan-import-type MailAttachment from EmailContent
 *
 * @phpstan-type StagedAttachment array{asset_id: string|null, name: string, mime_type: string, size: int, checksum: string, source_kind: string, disposition: string, content_id: string|null, file: UploadedFile|null, stream: resource|null, catalog: Asset|null}
 */
class AttachmentLocalizer
{
    public const MAX_FILE_BYTES = MailMediaSlots::MAX_FILE_BYTES;

    public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;

    private const MIME_TYPES = MailMediaSlots::MIME_TYPES;

    public function __construct(private RemoteAttachmentFetcher $remote, private MailMediaStorage $media) {}

    /**
     * Bounded local leases live only for this acceptance call; cleanup closes their tmpfile resources.
     * No durable files or staging rows are written before the acceptance transaction.
     *
     * @param  ManagedMessageInput  $input
     * @return list<StagedAttachment>
     */
    public function stage(Client $client, array $input): array
    {
        $staged = [];
        $seenAssets = [];
        $total = 0;
        try {
            foreach (['attachments' => 'attachment', 'inline_assets' => 'inline'] as $key => $defaultDisposition) {
                foreach ($input[$key] ?? [] as $item) {
                    $this->media->assertConnection($client);
                    $item = is_string($item) ? ['asset_id' => $item] : $item;
                    $catalog = null;
                    $lease = null;
                    $file = null;
                    if (isset($item['asset_id'])) {
                        $catalog = Asset::query()->where('client_id', $client->id)->whereNull('archived_at')->find($item['asset_id']);
                        if (! $catalog || in_array($catalog->id, $seenAssets, true)) {
                            throw ValidationException::withMessages(['attachments' => 'Every catalog asset must be unique, active and belong to this client.']);
                        }
                        $seenAssets[] = $catalog->id;
                        $name = $item['name'] ?? $catalog->name;
                        $mime = $catalog->mime_type;
                        $size = $catalog->size;
                        $checksum = $catalog->checksum;
                        if ($catalog->media_id === null) {
                            try {
                                $catalogStream = $this->media->readStream($catalog);
                            } catch (RuntimeException $exception) {
                                throw ValidationException::withMessages(['attachments' => 'The catalog asset bytes are unavailable.']);
                            }
                            [$lease, $file, $mime, $size, $checksum] = $this->materialize($catalogStream, $name);
                            if ($size !== $catalog->size || ! hash_equals($catalog->checksum, $checksum)) {
                                fclose($lease);
                                throw ValidationException::withMessages(['attachments' => 'The catalog asset bytes no longer match their recorded snapshot.']);
                            }
                        }
                        $source = 'asset';
                    } else {
                        [$inputStream, $name, $source] = $this->source($item);
                        [$lease, $file, $mime, $size, $checksum] = $this->materialize($inputStream, $item['name'] ?? $name);
                        $name = $item['name'] ?? $name;
                    }
                    $staged[] = ['asset_id' => $catalog?->id, 'name' => $name, 'mime_type' => $mime, 'size' => $size, 'checksum' => $checksum, 'source_kind' => $source, 'disposition' => $item['disposition'] ?? $defaultDisposition, 'content_id' => $item['content_id'] ?? null, 'file' => $file, 'stream' => $lease, 'catalog' => $catalog];
                    $total += $size;
                    if ($size > self::MAX_FILE_BYTES || $total > self::MAX_TOTAL_BYTES) {
                        throw ValidationException::withMessages(['attachments' => 'Each attachment must be at most 10 MiB and the total at most 20 MiB.']);
                    }
                }
            }

            return $staged;
        } catch (Throwable $exception) {
            $this->cleanup($staged);
            throw $exception;
        }
    }

    /**
     * Localize staged descriptors and retain durable attachment evidence for the owning message.
     *
     * @param  list<StagedAttachment>  $attachments
     */
    public function persist(EmailMessage $message, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            $asset = $message->messageAssets()->create([
                'asset_id' => $attachment['asset_id'], 'disk' => ManagedConfiguration::string('storage.disk', 'local'), 'path' => '',
                'name' => $attachment['name'], 'mime_type' => $attachment['mime_type'], 'size' => $attachment['size'], 'checksum' => $attachment['checksum'],
                'source_kind' => $attachment['source_kind'], 'owns_file' => true, 'disposition' => $attachment['disposition'], 'content_id' => $attachment['content_id'],
            ]);
            if ($attachment['catalog'] !== null && $attachment['file'] === null) {
                $catalog = Asset::query()->lockForUpdate()->findOrFail($attachment['catalog']->id);
                $this->media->copy($catalog, $asset);
            } else {
                $this->media->store($asset, $attachment['file'] ?? throw new RuntimeException('The prepared attachment file is unavailable.'));
            }
            if ($asset->size !== $attachment['size'] || ! hash_equals($attachment['checksum'], $asset->checksum)) {
                throw ValidationException::withMessages(['attachments' => 'The attachment changed while its immutable snapshot was accepted.']);
            }
        }
    }

    /**
     * Release staged bytes that were not retained by a successfully accepted message.
     *
     * @param  list<StagedAttachment>  $attachments
     */
    public function cleanup(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if (is_resource($attachment['stream'])) {
                fclose($attachment['stream']);
            }
        }
    }

    /**
     * Identify the staged byte descriptors already retained by an accepted message.
     *
     * @param  list<StagedAttachment>  $attachments
     */
    public function retained(array $attachments): void
    {
        $this->cleanup($attachments);
    }

    /** @param MailAttachment $item
     * @return array{resource, string, string}
     */
    private function source(array $item): array
    {
        if (($item['file'] ?? null) instanceof UploadedFile) {
            $stream = fopen($item['file']->getRealPath(), 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('The uploaded attachment could not be read.');
            }

            return [$stream, mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', basename($item['file']->getClientOriginalName())) ?: 'attachment', 0, 255), 'file'];
        }
        if (isset($item['content_base64'])) {
            $bytes = base64_decode($item['content_base64'], true);
            if ($bytes === false || strlen($bytes) > self::MAX_FILE_BYTES || base64_encode($bytes) !== $item['content_base64']) {
                throw ValidationException::withMessages(['attachments' => 'Attachment content must be strict base64 and at most 10 MiB.']);
            }
            $stream = tmpfile();
            if (! is_resource($stream) || fwrite($stream, $bytes) !== strlen($bytes) || ! rewind($stream)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                throw new RuntimeException('The attachment could not be prepared.');
            }

            return [$stream, 'attachment', 'base64'];
        }
        if (isset($item['url'])) {
            return [$this->remote->fetch($item['url'], self::MAX_FILE_BYTES), 'attachment', 'url'];
        }

        throw ValidationException::withMessages(['attachments' => 'An attachment source is required.']);
    }

    /** @param resource $source
     * @return array{resource, UploadedFile, string, int, string}
     */
    private function materialize($source, string $name): array
    {
        $lease = tmpfile();
        if (! is_resource($lease)) {
            fclose($source);
            throw new RuntimeException('The attachment could not be prepared.');
        }
        try {
            $size = stream_copy_to_stream($source, $lease, self::MAX_FILE_BYTES + 1);
            if ($size === false || $size > self::MAX_FILE_BYTES) {
                throw ValidationException::withMessages(['attachments' => 'Each attachment must be at most 10 MiB.']);
            }
            rewind($lease);
            $sample = fread($lease, 65536);
            if ($sample === false) {
                throw new RuntimeException('Attachment bytes could not be read.');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($sample);
            if (! in_array($mime, self::MIME_TYPES, true)) {
                throw ValidationException::withMessages(['attachments' => 'This attachment MIME type is not supported.']);
            }
            rewind($lease);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $lease);
            rewind($lease);
            $metadata = stream_get_meta_data($lease);
            if (! isset($metadata['uri'])) {
                throw new RuntimeException('The temporary attachment path is unavailable.');
            }
            $file = new UploadedFile($metadata['uri'], $name, $mime, null, true);

            return [$lease, $file, $mime, $size, hash_final($hash)];
        } catch (Throwable $exception) {
            fclose($lease);
            throw $exception;
        } finally {
            fclose($source);
        }
    }
}
