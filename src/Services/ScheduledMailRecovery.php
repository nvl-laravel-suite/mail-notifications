<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Events\ScheduledMailFailed;
use Nvl\MailNotifications\Events\ScheduledMailRecovered;
use Nvl\MailNotifications\Events\ScheduledMailRetrying;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\DatabaseTimestamp;

/**
 * Recovers expired claims without incrementing their attempt count.
 *
 * Transaction ownership is intentional because expired-claim recovery is a
 * reusable atomic persistence boundary.
 */
final readonly class ScheduledMailRecovery
{
    private const string EXPIRED_CLAIM = 'claim_expired';

    /**
     * Create the expired-claim recovery service.
     */
    public function __construct(
        private ScheduledMailConfiguration $configuration,
        private MailTrackingEventDispatcher $events,
    ) {}

    /**
     * Recover a bounded batch of expired claims.
     */
    public function recover(?int $limit = null): int
    {
        if (! $this->configuration->enabled()) {
            return 0;
        }

        $batchSize = $this->configuration->batchSize($limit);
        $model = new ScheduledMailMessage;

        return $model->getConnection()->transaction(function () use (
            $batchSize,
        ): int {
            $now = CarbonImmutable::now('UTC');
            $databaseNow = DatabaseTimestamp::format($now);
            $expired = ScheduledMailMessage::query()
                ->where('status', ScheduledMailStatus::Processing->value)
                ->whereNotNull('claim_token')
                ->where('locked_until', '<=', $databaseNow)
                ->orderBy('locked_until')
                ->orderBy('id')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();
            $recovered = 0;

            foreach ($expired as $message) {
                $token = $message->claim_token;

                if (! is_string($token) || $token === '') {
                    continue;
                }

                $willRetry = $message->attempts < $message->max_attempts;
                $updates = [
                    'status' => $willRetry
                        ? ScheduledMailStatus::Pending->value
                        : ScheduledMailStatus::Failed->value,
                    'claim_token' => null,
                    'locked_until' => null,
                    'last_error' => self::EXPIRED_CLAIM,
                ];
                $availableAt = null;

                if ($willRetry) {
                    $availableAt = $now->addSeconds(
                        $this->configuration->retryDelaySeconds(
                            $message->attempts,
                        ),
                    );
                    $updates['available_at'] = DatabaseTimestamp::format(
                        $availableAt,
                    );
                } else {
                    $updates['failed_at'] = $databaseNow;
                }

                $updated = ScheduledMailMessage::query()
                    ->whereKey($message->id)
                    ->where('status', ScheduledMailStatus::Processing->value)
                    ->where('claim_token', $token)
                    ->where('locked_until', '<=', $databaseNow)
                    ->update($updates);

                if ($updated !== 1) {
                    continue;
                }

                $recovered++;
                $this->events->dispatch(new ScheduledMailRecovered(
                    messageId: $message->id,
                    attempt: $message->attempts,
                    willRetry: $willRetry,
                ));

                if ($willRetry) {
                    $this->events->dispatch(new ScheduledMailRetrying(
                        messageId: $message->id,
                        attempt: $message->attempts,
                        availableAt: $availableAt,
                    ));
                } else {
                    $this->events->dispatch(new ScheduledMailFailed(
                        messageId: $message->id,
                        attempt: $message->attempts,
                        failureType: self::EXPIRED_CLAIM,
                    ));
                }
            }

            return $recovered;
        });
    }
}
