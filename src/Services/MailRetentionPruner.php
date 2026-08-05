<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Exceptions\MailRetentionException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Nvl\MailNotifications\ValueObjects\MailRetentionResult;

/**
 * Prunes bounded, allowlisted database history without provider side effects.
 */
final readonly class MailRetentionPruner
{
    /**
     * Create the database retention service.
     */
    public function __construct(
        private MailRetentionConfiguration $configuration,
    ) {}

    /**
     * Preview or prune one bounded set of retained database rows.
     */
    public function prune(
        bool $dryRun = false,
        ?int $limit = null,
        ?CarbonImmutable $cutoff = null,
    ): MailRetentionResult {
        $now = CarbonImmutable::now('UTC');
        $explicitCutoff = $cutoff?->setTimezone('UTC');

        if ($explicitCutoff?->isAfter($now)) {
            throw new MailRetentionException(
                'Mail notification retention cutoff cannot be in the future.',
            );
        }

        $notificationCutoff = $explicitCutoff
            ?? $now->subDays(
                $this->configuration->notificationRetentionDays(),
            );
        $scheduledMessagePruningEnabled = $this->configuration
            ->scheduledMessagePruningEnabled();
        $scheduledMessageCutoff = $scheduledMessagePruningEnabled
            ? $explicitCutoff ?? $now->subDays(
                $this->configuration->scheduledMessageRetentionDays(),
            )
            : null;
        $effectiveLimit = $this->configuration->limit($limit);
        $batchSize = $this->configuration->batchSize();
        $notificationStatuses = array_map(
            static fn (MailDeliveryStatus $status): string => $status->value,
            $this->configuration->notificationStatuses(),
        );
        $scheduledMessageStatuses = $scheduledMessagePruningEnabled
            ? $this->configuration->scheduledMessageStatuses()
            : [];
        $connection = (new MailNotification)->getConnection();

        return $connection->transaction(function () use (
            $batchSize,
            $dryRun,
            $effectiveLimit,
            $notificationCutoff,
            $notificationStatuses,
            $scheduledMessageCutoff,
            $scheduledMessageStatuses,
        ): MailRetentionResult {
            $notificationIds = $this->notificationCandidateIds(
                cutoff: $notificationCutoff,
                statuses: $notificationStatuses,
                limit: $effectiveLimit,
                lock: ! $dryRun,
            );
            $scheduledMessageIds = $scheduledMessageCutoff
                instanceof CarbonImmutable
                    ? $this->scheduledMessageCandidateIds(
                        cutoff: $scheduledMessageCutoff,
                        statuses: $scheduledMessageStatuses,
                        limit: $effectiveLimit,
                        lock: ! $dryRun,
                    )
                    : [];
            $providerEventCount = $this->providerEventCount(
                $notificationIds,
                $batchSize,
            );

            if (! $dryRun) {
                $this->deleteNotifications($notificationIds, $batchSize);
                $this->deleteScheduledMessages(
                    $scheduledMessageIds,
                    $batchSize,
                );
            }

            return new MailRetentionResult(
                notificationCutoff: $notificationCutoff,
                scheduledMessageCutoff: $scheduledMessageCutoff,
                notificationCount: count($notificationIds),
                providerEventCount: $providerEventCount,
                scheduledMessageCount: count($scheduledMessageIds),
                dryRun: $dryRun,
            );
        });
    }

    /**
     * Select notification IDs deterministically.
     *
     * @param  list<string>  $statuses
     * @return list<string>
     */
    private function notificationCandidateIds(
        CarbonImmutable $cutoff,
        array $statuses,
        int $limit,
        bool $lock,
    ): array {
        $databaseCutoff = DatabaseTimestamp::format($cutoff);
        $query = MailNotification::query()
            ->whereIn('status', $statuses)
            ->where(static function (EloquentBuilder $query) use (
                $databaseCutoff,
            ): void {
                $query->where('status_changed_at', '<', $databaseCutoff)
                    ->orWhere(static function (
                        EloquentBuilder $fallback,
                    ) use ($databaseCutoff): void {
                        $fallback->whereNull('status_changed_at')
                            ->where('created_at', '<', $databaseCutoff);
                    });
            })
            ->orderByRaw('COALESCE(status_changed_at, created_at) ASC')
            ->orderBy('id')
            ->limit($limit);

        if ($lock) {
            $query->lockForUpdate();
        }

        $ids = [];

        foreach ($query->get(['id']) as $notification) {
            $ids[] = $notification->id;
        }

        return $ids;
    }

    /**
     * Select terminal scheduled-message IDs deterministically.
     *
     * @param  list<ScheduledMailStatus>  $statuses
     * @return list<string>
     */
    private function scheduledMessageCandidateIds(
        CarbonImmutable $cutoff,
        array $statuses,
        int $limit,
        bool $lock,
    ): array {
        $databaseCutoff = DatabaseTimestamp::format($cutoff);
        $query = ScheduledMailMessage::query()
            ->where(static function (EloquentBuilder $query) use (
                $databaseCutoff,
                $statuses,
            ): void {
                foreach ($statuses as $status) {
                    $terminalTimestamp = match ($status) {
                        ScheduledMailStatus::Sent => 'sent_at',
                        ScheduledMailStatus::Failed => 'failed_at',
                        ScheduledMailStatus::Cancelled => 'cancelled_at',
                        ScheduledMailStatus::Pending,
                        ScheduledMailStatus::Processing => throw new MailRetentionException(
                            'Only terminal scheduled-mail statuses may be pruned.',
                        ),
                    };

                    $query->orWhere(static function (
                        EloquentBuilder $statusQuery,
                    ) use (
                        $databaseCutoff,
                        $status,
                        $terminalTimestamp,
                    ): void {
                        $statusQuery
                            ->where('status', $status->value)
                            ->where(static function (
                                EloquentBuilder $ageQuery,
                            ) use (
                                $databaseCutoff,
                                $terminalTimestamp,
                            ): void {
                                $ageQuery->where(
                                    $terminalTimestamp,
                                    '<',
                                    $databaseCutoff,
                                )->orWhere(static function (
                                    EloquentBuilder $fallback,
                                ) use (
                                    $databaseCutoff,
                                    $terminalTimestamp,
                                ): void {
                                    $fallback
                                        ->whereNull($terminalTimestamp)
                                        ->where(
                                            'updated_at',
                                            '<',
                                            $databaseCutoff,
                                        );
                                });
                            });
                    });
                }
            })
            ->orderByRaw(
                'COALESCE(sent_at, failed_at, cancelled_at, updated_at) ASC',
            )
            ->orderBy('id')
            ->limit($limit);

        if ($lock) {
            $query->lockForUpdate();
        }

        $ids = [];

        foreach ($query->get(['id']) as $message) {
            $ids[] = $message->id;
        }

        return $ids;
    }

    /**
     * Count provider events owned by selected notifications.
     *
     * @param  list<string>  $notificationIds
     * @param  int<1, max>  $batchSize
     */
    private function providerEventCount(
        array $notificationIds,
        int $batchSize,
    ): int {
        $count = 0;

        foreach (array_chunk($notificationIds, $batchSize) as $ids) {
            $count += MailNotificationEvent::query()
                ->whereIn('mail_notification_id', $ids)
                ->count();
        }

        return $count;
    }

    /**
     * Delete selected notifications with their owned provider events.
     *
     * The explicit child deletion keeps pruning deterministic on database
     * sessions that do not enforce foreign keys. The package schema still
     * requires an ownership cascade as a defensive integrity constraint.
     *
     * @param  list<string>  $notificationIds
     * @param  int<1, max>  $batchSize
     */
    private function deleteNotifications(
        array $notificationIds,
        int $batchSize,
    ): void {
        foreach (array_chunk($notificationIds, $batchSize) as $ids) {
            MailNotificationEvent::query()
                ->whereIn('mail_notification_id', $ids)
                ->delete();

            $deleted = MailNotification::query()->whereKey($ids)->delete();

            if ($deleted !== count($ids)) {
                throw new MailRetentionException(
                    'The mail notification retention candidate set changed during pruning.',
                );
            }
        }
    }

    /**
     * Delete selected terminal scheduled messages.
     *
     * @param  list<string>  $scheduledMessageIds
     * @param  int<1, max>  $batchSize
     */
    private function deleteScheduledMessages(
        array $scheduledMessageIds,
        int $batchSize,
    ): void {
        foreach (array_chunk($scheduledMessageIds, $batchSize) as $ids) {
            $deleted = ScheduledMailMessage::query()
                ->whereKey($ids)
                ->delete();

            if ($deleted !== count($ids)) {
                throw new MailRetentionException(
                    'The scheduled-mail retention candidate set changed during pruning.',
                );
            }
        }
    }
}
