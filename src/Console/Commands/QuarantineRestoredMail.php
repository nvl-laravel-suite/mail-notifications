<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Support\ManagedConfiguration;

/** Trusted operator entry point for the opt-in managed delivery engine.
 * @internal
 */
class QuarantineRestoredMail extends Command
{
    protected $signature = 'nvl:mail-notifications:delivery:quarantine-restored {--before= : Absolute ISO8601 restore cutoff with timezone} {--limit=1000 : Maximum messages to hold (1-1000)} {--dry-run : Preview without changing messages}';

    protected $description = 'Hold restored send intents for provider reconciliation before resuming outbound delivery';

    /**
     * Quarantine restored submission state to prevent a backup restore from blindly resending mail.
     */
    public function handle(): int
    {
        if (ManagedConfiguration::sendingEnabled()) {
            $this->error('Pause outbound delivery with NVL_MAIL_NOTIFICATIONS_DELIVERY_ENABLED=false before quarantining restored mail; a legacy MAIL_DELIVERY_ENABLED value is ignored while the canonical name is set.');

            return self::FAILURE;
        }
        $before = $this->option('before');
        if (! is_string($before) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', $before)) {
            $this->error('Provide --before as an absolute ISO8601 timestamp with timezone, such as 2026-11-01T12:00:00Z.');

            return self::FAILURE;
        }
        $date = DateTimeImmutable::createFromFormat(str_contains($before, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP', $before);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $this->error('The --before cutoff must be a valid ISO8601 timestamp.');

            return self::FAILURE;
        }
        $cutoff = CarbonImmutable::instance($date)->utc();
        if ($cutoff->isFuture()) {
            $this->error('The --before cutoff must not be in the future.');

            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 1000.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $eligible = EmailMessage::query()->whereIn('status', ['accepted', 'queued', 'sending', 'failed', 'blocked'])->where('created_at', '<=', $cutoff);
        $ids = (clone $eligible)->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id');
        $held = 0;
        foreach ($ids as $id) {
            $held += $eligible->getModel()->getConnection()->transaction(function () use ($eligible, $id, $dryRun): int {
                $message = (clone $eligible)->whereKey($id)->lockForUpdate()->first();
                if ($message === null) {
                    return 0;
                }
                if (! $dryRun) {
                    $message->update(['status' => 'unknown', 'error_code' => 'restore_reconciliation_required',
                        'error_message' => 'Restored send intent requires provider reconciliation before any resubmission.',
                        'lease_until' => null, 'next_attempt_at' => null, 'dispatched_at' => null]);
                    $message->attempts()->whereIn('status', ['pending', 'sending', 'failed', 'blocked'])->update([
                        'status' => 'unknown', 'retryable' => false, 'error_code' => 'restore_reconciliation_required',
                        'error_message' => 'Restored send intent requires provider reconciliation before any resubmission.', 'completed_at' => now(),
                    ]);
                }

                return 1;
            });
        }
        $remaining = $eligible->count();
        $this->info($dryRun ? 'Would hold '.$held.' messages; '.$remaining.' eligible remain unchanged.' : 'Held '.$held.' messages; '.$remaining.' eligible remain.');

        return self::SUCCESS;
    }
}
