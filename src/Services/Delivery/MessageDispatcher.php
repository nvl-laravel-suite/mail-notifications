<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Illuminate\Support\Facades\DB;
use Nvl\MailNotifications\Jobs\SendEmailMessage;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Throwable;

/**
 * Claims accepted delivery work for the queue and recovers uncertain expired submission leases.
 *
 * @internal
 */
class MessageDispatcher
{
    public function __construct(private ClientWebhookPublisher $clientWebhooks) {}

    /**
     * Queue a bounded set of due accepted intents without duplicating an active dispatch claim.
     */
    public function dispatchPending(int $limit = 100): int
    {
        $this->recoverExpiredLeases();
        if (! ManagedConfiguration::sendingEnabled()) {
            return 0;
        }
        $ids = EmailMessage::query()->whereIn('status', ['accepted', 'queued'])->where(fn ($q) => $q->whereNull('send_at')->orWhere('send_at', '<=', now()))->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(5)))->orderBy('created_at')->limit(min(1000, max(1, $limit)))->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new \UnexpectedValueException('Message identifiers must be strings.');
            }
            if ($this->dispatchMessage($id)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Claim one eligible intent and dispatch after its source commit; a failed enqueue remains recoverable.
     */
    public function dispatchMessage(string $id): bool
    {
        $claimed = DB::connection(ManagedConfiguration::connection())->transaction(function () use ($id): bool {
            $message = EmailMessage::query()->lockForUpdate()->whereKey($id)->first();
            if (! ManagedConfiguration::sendingEnabled() || ! $message || ! in_array($message->status, ['accepted', 'queued'], true) || $message->send_at?->isFuture() || $message->next_attempt_at?->isFuture() || $message->dispatched_at?->greaterThan(now()->subMinutes(5))) {
                return false;
            }
            $message->update(['status' => 'queued', 'dispatched_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return false;
        }
        try {
            SendEmailMessage::dispatch($id)->afterCommit();
        } catch (Throwable $exception) {
            EmailMessage::whereKey($id)->where('status', 'queued')->update(['dispatched_at' => null]);
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * Mark abandoned sending attempts uncertain so recovery never silently repeats a potentially accepted submission.
     */
    public function recoverExpiredLeases(): int
    {
        $ids = EmailMessage::where('status', 'sending')->where('lease_until', '<=', now())->limit(1000)->pluck('id');
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new \UnexpectedValueException('Message identifiers must be strings.');
            }
            DB::connection(ManagedConfiguration::connection())->transaction(function () use ($id): void {
                $message = EmailMessage::query()->lockForUpdate()->whereKey($id)->first();
                if ($message?->status !== 'sending' || ! $message->lease_until?->isPast()) {
                    return;
                }
                $message->attempts()->where('status', 'sending')->update(['status' => 'unknown', 'error_code' => 'worker_interrupted', 'completed_at' => now()]);
                $message->update(['status' => 'unknown', 'error_code' => 'worker_interrupted', 'error_message' => 'Submission may have reached the provider. Reconciliation is required.', 'lease_until' => null]);
                $lastAttempt = $message->attempts()->orderByDesc('number')->first();
                $this->clientWebhooks->publish($message, 'message.unknown', 'lease-expired:'.$message->id.':'.($lastAttempt->number ?? ''));
            });
        }

        return $ids->count();
    }
}
