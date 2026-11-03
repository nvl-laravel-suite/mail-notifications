<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\MailNotifications\Exceptions\ManagedDeliveryConflict;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Throwable;

/**
 * Expires attachment bytes independently of durable attachment names, checksums and message evidence.
 *
 * @internal
 */
class AttachmentRetention
{
    public function __construct(private MailMediaStorage $media) {}

    private const FINAL_STATUSES = ['submitted', 'delivered', 'partially_delivered', 'failed', 'blocked', 'cancelled'];

    /**
     * Set byte-retention timing for a message's durable attachment records after its lifecycle outcome.
     */
    public function markDisposition(EmailMessage $message): void
    {
        if ($this->protected($message)) {
            return;
        }
        $anchor = $message->submitted_at ?? $message->cancelled_at ?? $message->completed_at ?? now();
        $message->messageAssets()->whereNull('bytes_expires_at')->update(['bytes_expires_at' => $anchor->addHours($this->retentionHours())]);
    }

    /**
     * Describe any known byte-availability block before a retry or provider submission.
     */
    public function availabilityReason(EmailMessage $message): ?string
    {
        foreach ($message->messageAssets()->get() as $asset) {
            if ($asset->bytes_deleted_at !== null || $asset->bytes_expires_at?->isPast()) {
                return 'attachment_expired';
            }
            try {
                $bytes = $this->media->bytes($asset);
                if (strlen($bytes) !== $asset->size || ! hash_equals($asset->checksum, hash('sha256', $bytes))) {
                    return 'asset_changed';
                }
            } catch (Throwable) {
                return 'asset_unavailable';
            }
        }

        return null;
    }

    /**
     * Recheck retained bytes before accepting a retry; expired evidence does not imply that file bytes still exist.
     */
    public function prepareRetry(EmailMessage $message): void
    {
        if ($this->availabilityReason($message) !== null) {
            throw new ManagedDeliveryConflict('Attachment bytes are expired or unavailable. Submit a new message with the files.');
        }
        $message->messageAssets()->update(['bytes_expires_at' => null]);
    }

    /**
     * Expire eligible owned bytes in bounded work while retaining their attachment metadata.
     */
    public function prune(bool $dryRun = false): int
    {
        $deadline = now()->addSeconds(min(300, max(1, ManagedConfiguration::integer('maintenance.max_seconds', 30))));
        $batchSize = min(1000, max(1, ManagedConfiguration::integer('maintenance.batch_size', 1000)));
        $candidates = MessageAsset::query()->whereNull('bytes_deleted_at')
            ->where(fn ($query) => $query->whereNull('bytes_expires_at')->orWhere('bytes_expires_at', '<=', now()))
            ->whereHas('emailMessage', fn ($query) => $query->whereIn('status', self::FINAL_STATUSES)->whereNull('next_attempt_at')
                ->whereDoesntHave('attempts', fn ($query) => $query->whereIn('status', ['sending', 'unknown'])));
        $count = 0;
        $after = null;
        while (! $this->deadlineReached($deadline)) {
            $ids = (clone $candidates)->when($after !== null, fn ($query) => $query->where('id', '>', $after))->orderBy('id')->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            foreach ($ids as $id) {
                if ($this->deadlineReached($deadline)) {
                    return $count;
                }
                $after = $id;
                $nativeAsset = null;
                $count += DB::connection(ManagedConfiguration::connection())->transaction(function () use ($id, $dryRun, &$nativeAsset): int {
                    $messageId = MessageAsset::whereKey($id)->value('email_message_id');
                    $message = EmailMessage::query()->lockForUpdate()->whereKey($messageId)->first();
                    if (! $message || $this->protected($message)) {
                        return 0;
                    }
                    $asset = $message->messageAssets()->lockForUpdate()->whereKey($id)->first();
                    if (! $asset || $asset->bytes_deleted_at !== null) {
                        return 0;
                    }
                    $expiresAt = $asset->bytes_expires_at;
                    if ($expiresAt === null) {
                        $anchor = $message->submitted_at ?? $message->cancelled_at ?? $message->completed_at ?? now();
                        $expiresAt = $anchor->addHours($this->retentionHours());
                        if (! $dryRun) {
                            $asset->update(['bytes_expires_at' => $expiresAt]);
                        }
                    }
                    if ($expiresAt->isFuture()) {
                        return 0;
                    }
                    if (! $dryRun) {
                        if ($asset->media_id !== null) {
                            $nativeAsset = $asset;

                            return 0;
                        }
                        if (! $this->deleteOwnedFile($asset)) {
                            return 0;
                        }
                        $asset->update(['bytes_deleted_at' => now()]);
                    }

                    return 1;
                });
                if ($nativeAsset instanceof MessageAsset) {
                    if ($nativeAsset->getConnection()->transactionLevel() > 0) {
                        $nativeAsset->getConnection()->afterCommit(fn () => $this->completeNativeDeletion($nativeAsset));
                    } elseif ($this->completeNativeDeletion($nativeAsset)) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Delete only bytes owned by this attachment through the configured storage capability.
     */
    public function deleteOwnedFile(MessageAsset $asset): bool
    {
        if ($asset->media_id !== null) {
            if ($asset->bytes_deleted_at !== null) {
                return true;
            }
            $asset->update(['bytes_expires_at' => $asset->bytes_expires_at ?? now()]);
            if ($asset->getConnection()->transactionLevel() > 0) {
                $asset->getConnection()->afterCommit(fn () => $this->completeNativeDeletion($asset));

                return false;
            }

            return $this->completeNativeDeletion($asset);
        }
        if (! $asset->owns_file) {
            return true;
        }
        try {
            $disk = Storage::disk($asset->disk);

            return ! $disk->exists($asset->path) || $disk->delete($asset->path);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function completeNativeDeletion(MessageAsset $asset): bool
    {
        try {
            $current = MessageAsset::query()->find($asset->id);
            if ($current === null || $current->bytes_deleted_at !== null || $current->media_id !== $asset->media_id) {
                return false;
            }
            $message = $current->emailMessage()->first();
            if ($message === null || $this->protected($message) || $current->bytes_expires_at === null || $current->bytes_expires_at->isFuture()) {
                return false;
            }
            if (! $this->media->deleteConfirmed($current)) {
                return false;
            }

            return MessageAsset::query()->whereKey($current->id)->where('media_id', $current->media_id)->whereNull('bytes_deleted_at')->update(['bytes_deleted_at' => now()]) === 1;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /** @phpstan-impure */
    private function deadlineReached(CarbonInterface $deadline): bool
    {
        return now()->greaterThanOrEqualTo($deadline);
    }

    private function retentionHours(): int
    {
        return min(720, max(1, ManagedConfiguration::integer('attachments.retention_hours', 72)));
    }

    private function protected(EmailMessage $message): bool
    {
        return ! in_array($message->status, self::FINAL_STATUSES, true)
            || $message->next_attempt_at !== null
            || $message->attempts()->whereIn('status', ['sending', 'unknown'])->exists();
    }
}
