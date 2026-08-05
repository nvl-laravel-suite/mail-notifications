<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Events\ScheduledMailFailed;
use Nvl\MailNotifications\Events\ScheduledMailRetrying;
use Nvl\MailNotifications\Events\ScheduledMailSent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Throwable;

/**
 * Finalizes claimed messages only while their opaque claim token still matches.
 *
 * Transaction ownership is intentional because fenced finalization is a
 * reusable atomic persistence boundary.
 */
final readonly class ScheduledMailFinalizer
{
    /**
     * Create the claim finalizer.
     */
    public function __construct(
        private ScheduledMailConfiguration $configuration,
        private MailTrackingEventDispatcher $events,
    ) {}

    /**
     * Mark one matching claim as sent.
     */
    public function markSent(string $messageId, string $claimToken): bool
    {
        $model = new ScheduledMailMessage;

        return $model->getConnection()->transaction(function () use (
            $messageId,
            $claimToken,
        ): bool {
            $message = $this->lockedClaim($messageId, $claimToken);

            if (! $message instanceof ScheduledMailMessage) {
                return false;
            }

            $updated = $this->fencedQuery($messageId, $claimToken)->update([
                'status' => ScheduledMailStatus::Sent->value,
                'sent_at' => DatabaseTimestamp::format(
                    CarbonImmutable::now('UTC'),
                ),
                'claim_token' => null,
                'locked_until' => null,
                'last_error' => null,
            ]);

            if ($updated !== 1) {
                return false;
            }

            $this->events->dispatch(new ScheduledMailSent(
                messageId: $message->id,
                attempt: $message->attempts,
            ));

            return true;
        });
    }

    /**
     * Retry or exhaust one matching claim without persisting exception messages.
     */
    public function markFailure(
        string $messageId,
        string $claimToken,
        Throwable $exception,
        bool $terminal = false,
    ): bool {
        $model = new ScheduledMailMessage;

        return $model->getConnection()->transaction(function () use (
            $messageId,
            $claimToken,
            $exception,
            $terminal,
        ): bool {
            $message = $this->lockedClaim($messageId, $claimToken);

            if (! $message instanceof ScheduledMailMessage) {
                return false;
            }

            $failureType = $this->boundedFailureType($exception::class);
            $isExhausted = $terminal
                || $message->attempts >= $message->max_attempts;
            $now = CarbonImmutable::now('UTC');

            if ($isExhausted) {
                $updated = $this->fencedQuery(
                    $messageId,
                    $claimToken,
                )->update([
                    'status' => ScheduledMailStatus::Failed->value,
                    'failed_at' => DatabaseTimestamp::format($now),
                    'claim_token' => null,
                    'locked_until' => null,
                    'last_error' => $failureType,
                ]);

                if ($updated === 1) {
                    $this->events->dispatch(new ScheduledMailFailed(
                        messageId: $message->id,
                        attempt: $message->attempts,
                        failureType: $failureType,
                    ));
                }

                return $updated === 1;
            }

            $availableAt = $now->addSeconds(
                $this->configuration->retryDelaySeconds($message->attempts),
            );
            $updated = $this->fencedQuery(
                $messageId,
                $claimToken,
            )->update([
                'status' => ScheduledMailStatus::Pending->value,
                'available_at' => DatabaseTimestamp::format($availableAt),
                'claim_token' => null,
                'locked_until' => null,
                'last_error' => $failureType,
            ]);

            if ($updated === 1) {
                $this->events->dispatch(new ScheduledMailRetrying(
                    messageId: $message->id,
                    attempt: $message->attempts,
                    availableAt: $availableAt,
                ));
            }

            return $updated === 1;
        });
    }

    /**
     * Lock one active matching claim before deciding its transition.
     */
    private function lockedClaim(
        string $messageId,
        string $claimToken,
    ): ?ScheduledMailMessage {
        return ScheduledMailMessage::query()
            ->whereKey($messageId)
            ->where('status', ScheduledMailStatus::Processing->value)
            ->where('claim_token', $claimToken)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Build the token-fenced finalization query.
     *
     * @return Builder<ScheduledMailMessage>
     */
    private function fencedQuery(
        string $messageId,
        string $claimToken,
    ): Builder {
        return ScheduledMailMessage::query()
            ->whereKey($messageId)
            ->where('status', ScheduledMailStatus::Processing->value)
            ->where('claim_token', $claimToken);
    }

    /**
     * Bound a safe exception class or generic failure code for persistence.
     */
    private function boundedFailureType(string $failureType): string
    {
        return mb_substr($failureType, 0, 255);
    }
}
