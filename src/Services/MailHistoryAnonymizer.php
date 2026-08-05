<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Exceptions\MailRetentionException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Nvl\MailNotifications\ValueObjects\MailAnonymizationResult;

/**
 * Anonymizes bounded retained history without deleting lifecycle rows.
 *
 * Transaction ownership is intentional because this service is the explicit
 * reusable write boundary for one complete anonymization stage.
 */
final readonly class MailHistoryAnonymizer
{
    /**
     * Create the database history anonymizer.
     */
    public function __construct(
        private MailAnonymizationConfiguration $configuration,
    ) {}

    /**
     * Preview or anonymize independently bounded retained data sets.
     */
    public function anonymize(
        bool $dryRun = false,
        ?int $limit = null,
        ?CarbonImmutable $cutoff = null,
    ): MailAnonymizationResult {
        if (! $this->configuration->enabled()) {
            throw new MailRetentionException(
                'Mail notification anonymization is disabled.',
            );
        }

        $now = CarbonImmutable::now('UTC');
        $explicitCutoff = $cutoff?->setTimezone('UTC');

        if ($explicitCutoff?->isAfter($now)) {
            throw new MailRetentionException(
                'Mail notification anonymization cutoff cannot be in the future.',
            );
        }

        $notificationCutoff = $explicitCutoff ?? $now->subDays(
            $this->configuration->notificationRetentionDays(),
        );
        $scheduledEnabled = $this->configuration
            ->scheduledMessageAnonymizationEnabled();
        $scheduledCutoff = $scheduledEnabled
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
        $scheduledStatuses = $scheduledEnabled
            ? $this->configuration->scheduledMessageStatuses()
            : [];
        $connection = (new MailNotification)->getConnection();

        return $connection->transaction(function () use (
            $batchSize,
            $dryRun,
            $effectiveLimit,
            $notificationCutoff,
            $notificationStatuses,
            $now,
            $scheduledCutoff,
            $scheduledStatuses,
        ): MailAnonymizationResult {
            $notificationIds = $this->notificationCandidateIds(
                cutoff: $notificationCutoff,
                statuses: $notificationStatuses,
                limit: $effectiveLimit,
                lock: ! $dryRun,
            );
            $providerEventIds = $this->providerEventCandidateIds(
                cutoff: $notificationCutoff,
                statuses: $notificationStatuses,
                limit: $effectiveLimit,
                lock: ! $dryRun,
            );
            $scheduledMessageIds = $scheduledCutoff
                instanceof CarbonImmutable
                    ? $this->scheduledMessageCandidateIds(
                        cutoff: $scheduledCutoff,
                        statuses: $scheduledStatuses,
                        limit: $effectiveLimit,
                        lock: ! $dryRun,
                    )
                    : [];

            if (! $dryRun) {
                $this->anonymizeNotifications(
                    $notificationIds,
                    $batchSize,
                    $now,
                );
                $this->anonymizeProviderEvents(
                    $providerEventIds,
                    $batchSize,
                    $now,
                );
                $this->anonymizeScheduledMessages(
                    $scheduledMessageIds,
                    $batchSize,
                    $now,
                );
            }

            return new MailAnonymizationResult(
                notificationCutoff: $notificationCutoff,
                scheduledMessageCutoff: $scheduledCutoff,
                notificationCount: count($notificationIds),
                providerEventCount: count($providerEventIds),
                scheduledMessageCount: count($scheduledMessageIds),
                dryRun: $dryRun,
            );
        });
    }

    /**
     * Select unredacted tracked notification IDs deterministically.
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
        $query = $this->eligibleNotificationQuery($cutoff, $statuses)
            ->whereNull('redacted_at')
            ->orderByRaw('COALESCE(status_changed_at, created_at) ASC')
            ->orderBy('id')
            ->limit($limit);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $this->ids($query);
    }

    /**
     * Build the reusable eligible notification history query.
     *
     * @param  list<string>  $statuses
     * @return Builder<MailNotification>
     */
    private function eligibleNotificationQuery(
        CarbonImmutable $cutoff,
        array $statuses,
    ): Builder {
        $databaseCutoff = DatabaseTimestamp::format($cutoff);

        return MailNotification::query()
            ->whereIn('status', $statuses)
            ->where(static function (Builder $query) use (
                $databaseCutoff,
            ): void {
                $query->where(
                    'status_changed_at',
                    '<',
                    $databaseCutoff,
                )
                    ->orWhere(static function (
                        Builder $fallback,
                    ) use ($databaseCutoff): void {
                        $fallback->whereNull('status_changed_at')
                            ->where('created_at', '<', $databaseCutoff);
                    });
            });
    }

    /**
     * Select provider events owned by eligible retained notifications.
     *
     * @param  list<string>  $statuses
     * @return list<string>
     */
    private function providerEventCandidateIds(
        CarbonImmutable $cutoff,
        array $statuses,
        int $limit,
        bool $lock,
    ): array {
        $eligibleNotificationIds = $this->eligibleNotificationQuery(
            $cutoff,
            $statuses,
        )->select('id');
        $query = MailNotificationEvent::query()
            ->whereNull('redacted_at')
            ->whereIn('mail_notification_id', $eligibleNotificationIds)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $this->ids($query);
    }

    /**
     * Select unredacted terminal scheduled-message IDs deterministically.
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
            ->whereNull('redacted_at')
            ->where(static function (Builder $query) use (
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
                            'Only terminal scheduled-mail statuses may be anonymized.',
                        ),
                    };

                    $query->orWhere(static function (
                        Builder $statusQuery,
                    ) use (
                        $databaseCutoff,
                        $status,
                        $terminalTimestamp,
                    ): void {
                        $statusQuery
                            ->where('status', $status->value)
                            ->where(static function (
                                Builder $ageQuery,
                            ) use (
                                $databaseCutoff,
                                $terminalTimestamp,
                            ): void {
                                $ageQuery->where(
                                    $terminalTimestamp,
                                    '<',
                                    $databaseCutoff,
                                )->orWhere(static function (
                                    Builder $fallback,
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

        return $this->ids($query);
    }

    /**
     * Return selected string identifiers from one ordered model query.
     *
     * @template TModel of MailNotification|MailNotificationEvent|ScheduledMailMessage
     *
     * @param  Builder<TModel>  $query
     * @return list<string>
     */
    private function ids(Builder $query): array
    {
        $ids = [];

        foreach ($query->get(['id']) as $model) {
            $id = $model->getKey();

            if (! is_string($id)) {
                throw new MailRetentionException(
                    'Mail anonymization candidates must use string identities.',
                );
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Remove identifying notification content while retaining lifecycle facts.
     *
     * @param  list<string>  $ids
     * @param  int<1, max>  $batchSize
     */
    private function anonymizeNotifications(
        array $ids,
        int $batchSize,
        CarbonImmutable $redactedAt,
    ): void {
        foreach (array_chunk($ids, $batchSize) as $batch) {
            $models = MailNotification::query()->whereKey($batch)->get();

            if ($models->count() !== count($batch)) {
                throw $this->candidateSetChanged('notification');
            }

            foreach ($models as $notification) {
                $notification->forceFill([
                    'subject' => null,
                    'from_email' => null,
                    'from_name' => null,
                    'to_recipients' => [],
                    'cc_recipients' => null,
                    'bcc_recipients' => null,
                    'primary_recipient_email' => null,
                    'notifiable_type' => null,
                    'notifiable_id' => null,
                    'metadata' => null,
                    'redacted_at' => $redactedAt,
                ]);

                if (! $notification->save()) {
                    throw $this->candidateSetChanged('notification');
                }
            }
        }
    }

    /**
     * Remove provider-event payload content while retaining idempotency facts.
     *
     * @param  list<string>  $ids
     * @param  int<1, max>  $batchSize
     */
    private function anonymizeProviderEvents(
        array $ids,
        int $batchSize,
        CarbonImmutable $redactedAt,
    ): void {
        foreach (array_chunk($ids, $batchSize) as $batch) {
            $models = MailNotificationEvent::query()
                ->whereKey($batch)
                ->get();

            if ($models->count() !== count($batch)) {
                throw $this->candidateSetChanged('provider-event');
            }

            foreach ($models as $event) {
                $event->forceFill([
                    'metadata' => null,
                    'redacted_at' => $redactedAt,
                ]);

                if (! $event->save()) {
                    throw $this->candidateSetChanged('provider-event');
                }
            }
        }
    }

    /**
     * Remove terminal scheduled-message content while retaining outcome facts.
     *
     * @param  list<string>  $ids
     * @param  int<1, max>  $batchSize
     */
    private function anonymizeScheduledMessages(
        array $ids,
        int $batchSize,
        CarbonImmutable $redactedAt,
    ): void {
        foreach (array_chunk($ids, $batchSize) as $batch) {
            $models = ScheduledMailMessage::query()
                ->whereKey($batch)
                ->get();

            if ($models->count() !== count($batch)) {
                throw $this->candidateSetChanged('scheduled-message');
            }

            foreach ($models as $message) {
                $message->forceFill([
                    'payload' => [],
                    'to_recipients' => [],
                    'cc_recipients' => null,
                    'bcc_recipients' => null,
                    'last_error' => null,
                    'notifiable_type' => null,
                    'notifiable_id' => null,
                    'metadata' => null,
                    'redacted_at' => $redactedAt,
                ]);

                if (! $message->save()) {
                    throw $this->candidateSetChanged('scheduled-message');
                }
            }
        }
    }

    /**
     * Build one stable concurrent-candidate failure.
     */
    private function candidateSetChanged(string $dataset): MailRetentionException
    {
        return new MailRetentionException(sprintf(
            'The mail %s anonymization candidate set changed during processing.',
            $dataset,
        ));
    }
}
