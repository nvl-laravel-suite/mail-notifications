<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Models\StagedMailFile;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Throwable;

/**
 * Drains abandoned private-file staging records using bounded maintenance work.
 */
class MailFilePruner
{
    /**
     * Drain a bounded orphan/staging worklist; dry-run reports candidates without deleting owned bytes.
     *
     * @return array{files: int, records: int}
     */
    public function prune(bool $dryRun = false): array
    {
        $cutoff = now()->subHours(max(1, min(168, ManagedConfiguration::integer('storage.orphan_grace_hours', 24))));
        $deadline = now()->addSeconds(max(1, min(300, ManagedConfiguration::integer('maintenance.max_seconds', 30))));
        $batchSize = max(1, min(1000, ManagedConfiguration::integer('maintenance.batch_size', 1000)));
        $counts = ['files' => 0, 'records' => 0];

        foreach (StagedMailFile::where('created_at', '<=', $cutoff)->lazyById($batchSize) as $candidate) {
            if (now()->greaterThanOrEqualTo($deadline)) {
                break;
            }
            try {
                $result = DB::connection(ManagedConfiguration::connection())->transaction(function () use ($candidate, $cutoff, $dryRun): array {
                    $stage = StagedMailFile::whereKey($candidate->id)->where('created_at', '<=', $cutoff)->lockForUpdate()->first();
                    if (! $stage) {
                        return ['files' => 0, 'records' => 0];
                    }
                    $catalogReferenced = Asset::where('disk', $stage->disk)->where('path', $stage->path)->exists();
                    if (! $catalogReferenced && MessageAsset::where('disk', $stage->disk)->where('path', $stage->path)->where('owns_file', false)->exists()) {
                        return ['files' => 0, 'records' => 0];
                    }
                    $referenced = $catalogReferenced || MessageAsset::where('disk', $stage->disk)->where('path', $stage->path)->exists();
                    $files = 0;
                    if (! $referenced) {
                        $disk = Storage::disk($stage->disk);
                        if ($disk->exists($stage->path)) {
                            if (! $dryRun && ! $disk->delete($stage->path)) {
                                return ['files' => 0, 'records' => 0];
                            }
                            $files = 1;
                        }
                    }
                    if (! $dryRun) {
                        $stage->delete();
                    }

                    return ['files' => $files, 'records' => 1];
                });
                $counts['files'] += $result['files'];
                $counts['records'] += $result['records'];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $counts;
    }
}
