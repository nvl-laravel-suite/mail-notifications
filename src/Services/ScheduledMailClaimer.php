<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Events\ScheduledMailClaimed;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\DatabaseTimestamp;

/**
 * Atomically fences due scheduled messages and increments attempts once.
 *
 * Transaction ownership is intentional because claiming is a reusable atomic
 * persistence boundary that cannot preserve its fencing invariant otherwise.
 */
final readonly class ScheduledMailClaimer
{
    /**
     * Create the due-message claimer.
     */
    public function __construct(
        private ScheduledMailConfiguration $configuration,
        private MailTrackingEventDispatcher $events,
    ) {}

    /**
     * Claim a bounded batch of due messages.
     *
     * @param  list<string>  $excludedMessageIds
     * @return list<ScheduledMailMessage>
     */
    public function claim(
        ?int $limit = null,
        array $excludedMessageIds = [],
    ): array {
        if (! $this->configuration->enabled()) {
            return [];
        }

        $batchSize = $this->configuration->batchSize($limit);
        $leaseSeconds = $this->configuration->claimTtlSeconds();
        $model = new ScheduledMailMessage;

        return $model->getConnection()->transaction(function () use (
            $batchSize,
            $excludedMessageIds,
            $leaseSeconds,
        ): array {
            $now = CarbonImmutable::now('UTC');
            $databaseNow = DatabaseTimestamp::format($now);
            $query = ScheduledMailMessage::query()
                ->where('status', ScheduledMailStatus::Pending->value)
                ->where('available_at', '<=', $databaseNow)
                ->whereColumn('attempts', '<', 'max_attempts')
                ->orderBy('available_at')
                ->orderBy('id');

            if ($excludedMessageIds !== []) {
                $query->whereNotIn('id', $excludedMessageIds);
            }

            $candidates = $query
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();
            $claimed = [];

            foreach ($candidates as $candidate) {
                $token = (string) Str::uuid();
                $attempt = $candidate->attempts + 1;
                $updated = ScheduledMailMessage::query()
                    ->whereKey($candidate->id)
                    ->where('status', ScheduledMailStatus::Pending->value)
                    ->where('attempts', $candidate->attempts)
                    ->where('available_at', '<=', $databaseNow)
                    ->update([
                        'status' => ScheduledMailStatus::Processing->value,
                        'attempts' => $attempt,
                        'last_attempt_at' => $databaseNow,
                        'claim_token' => $token,
                        'locked_until' => DatabaseTimestamp::format(
                            $now->addSeconds($leaseSeconds),
                        ),
                    ]);

                if ($updated !== 1) {
                    continue;
                }

                $message = ScheduledMailMessage::query()
                    ->findOrFail($candidate->id);
                $claimed[] = $message;
                $this->events->dispatch(new ScheduledMailClaimed(
                    messageId: $message->id,
                    attempt: $message->attempts,
                ));
            }

            return $claimed;
        });
    }
}
