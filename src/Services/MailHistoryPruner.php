<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Models\WebhookReceipt;
use Nvl\MailNotifications\Services\Delivery\AttachmentRetention;
use Nvl\MailNotifications\Services\Delivery\MailMediaStorage;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Throwable;

/**
 * Prunes terminal delivery history and eligible bytes while retaining uncertain or active work.
 */
class MailHistoryPruner
{
    public function __construct(private AttachmentRetention $attachments, private MailMediaStorage $media) {}

    /**
     * Prune eligible terminal history in bounded batches; active and uncertain submission evidence remains retained.
     *
     * @return array{messages: int, receipts: int, assets: int}
     */
    public function prune(int $days = 365, bool $dryRun = false): array
    {
        $cutoff = now()->subDays($days);
        $deadline = now()->addSeconds(min(300, max(1, ManagedConfiguration::integer('maintenance.max_seconds', 30))));
        $messages = EmailMessage::query()->where('updated_at', '<', $cutoff)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereIn('status', ['delivered', 'partially_delivered', 'failed', 'cancelled', 'blocked'])
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->where('status', 'submitted')->where(function (Builder $query) use ($cutoff): void {
                            $query->where(function (Builder $query): void {
                                $query->whereHas('recipients')->whereDoesntHave('recipients', fn (Builder $query) => $query->whereNotIn('delivery_status', ['delivered', 'hard_bounced', 'rejected', 'suppressed']));
                            })->orWhere(function (Builder $query) use ($cutoff): void {
                                $query->whereNotNull('submitted_at')->where('submitted_at', '<', $cutoff)
                                    ->whereHas('providerAccount', fn (Builder $query) => $query->getQuery()->where('provider', 'smtp'))
                                    ->whereHas('attempts', fn (Builder $query) => $query->getQuery()->where('status', 'submitted'));
                            });
                        });
                    });
            })->whereNull('next_attempt_at')->whereDoesntHave('attempts', fn (Builder $query) => $query->whereIn('status', ['pending', 'sending', 'unknown']));
        $receipts = WebhookReceipt::query()->whereIn('status', ['processed', 'ignored', 'duplicate', 'unmatched', 'failed'])->where('processed_at', '<', $cutoff);
        $assets = Asset::query()->where('archived_at', '<', $cutoff)->whereNotIn('id', MessageAsset::select('asset_id')->whereNotNull('asset_id'));

        $nativeAssetsDeleted = 0;
        $counts = [
            'messages' => $this->drain($messages, $deadline, $dryRun, function (EmailMessage $message): bool {
                $cleaned = true;
                foreach ($message->messageAssets as $attachment) {
                    if (! $this->attachments->deleteOwnedFile($attachment)) {
                        $cleaned = false;
                    } elseif ($attachment->bytes_deleted_at === null) {
                        $attachment->update(['bytes_deleted_at' => now()]);
                    }
                }

                return $cleaned && (bool) $message->delete();
            }),
            'receipts' => $this->drain($receipts, $deadline, $dryRun, fn (WebhookReceipt $receipt): bool => (bool) $receipt->delete()),
            'assets' => $this->drain($assets, $deadline, $dryRun, function (Asset $asset) use (&$nativeAssetsDeleted): bool {
                if ($asset->media_id !== null) {
                    $asset->getConnection()->afterCommit(function () use ($asset, &$nativeAssetsDeleted): void {
                        try {
                            $current = Asset::query()->whereNotNull('archived_at')->find($asset->id);
                            if ($current !== null && $this->media->deleteConfirmed($current) && $current->delete()) {
                                $nativeAssetsDeleted++;
                            }
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    });

                    return false;
                }
                try {
                    $disk = Storage::disk($asset->disk);
                    if ($disk->exists($asset->path) && ! $disk->delete($asset->path)) {
                        return false;
                    }

                    return (bool) $asset->delete();
                } catch (Throwable $exception) {
                    report($exception);

                    return false;
                }
            }),
        ];
        $counts['assets'] += $nativeAssetsDeleted;

        return $counts;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): bool  $delete
     */
    private function drain(Builder $query, CarbonInterface $deadline, bool $dryRun, Closure $delete): int
    {
        $batchSize = min(1000, max(1, ManagedConfiguration::integer('maintenance.batch_size', 1000)));
        $key = $query->getModel()->getKeyName();
        $after = null;
        $count = 0;
        while (! $this->deadlineReached($deadline)) {
            $ids = (clone $query)->when($after !== null, fn (Builder $query) => $query->where($key, '>', $after))->orderBy($key)->limit($batchSize)->pluck($key);
            if ($ids->isEmpty()) {
                break;
            }
            foreach ($ids as $id) {
                if ($this->deadlineReached($deadline)) {
                    return $count;
                }
                $after = $id;
                if ($dryRun) {
                    $count += (clone $query)->whereKey($id)->exists() ? 1 : 0;

                    continue;
                }
                $count += DB::connection(ManagedConfiguration::connection())->transaction(function () use ($query, $id, $delete): int {
                    $model = (clone $query)->when($query->getModel() instanceof EmailMessage, fn (Builder $query) => $query->with('messageAssets'))->whereKey($id)->lockForUpdate()->first();

                    return $model !== null && $delete($model) ? 1 : 0;
                });
            }
        }

        return $count;
    }

    /** @phpstan-impure */
    private function deadlineReached(CarbonInterface $deadline): bool
    {
        return now()->greaterThanOrEqualTo($deadline);
    }
}
