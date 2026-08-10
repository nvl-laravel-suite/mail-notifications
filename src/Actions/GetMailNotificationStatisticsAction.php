<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\MailNotificationReadAbility;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\MailNotificationReadQueryBuilder;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadData;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;
use Nvl\MailNotifications\ValueObjects\MailNotificationStatistics;

/**
 * Aggregates authorized delivery health without loading sensitive payload columns.
 */
final readonly class GetMailNotificationStatisticsAction
{
    public function __construct(
        private MailNotificationReadAuthorization $authorization,
        private MailNotificationReadQueryBuilder $queries,
    ) {}

    public function execute(
        Authenticatable $actor,
        MailNotificationReadQuery $filters,
    ): MailNotificationStatistics {
        $this->authorization->authorize(
            MailNotificationReadAbility::Statistics,
            $actor,
        );
        $query = $this->queries->build($filters);
        $total = (clone $query)->count();
        $rawCounts = (clone $query)
            ->toBase()
            ->select('status')
            ->selectRaw('count(*) as aggregate_count')
            ->groupBy('status')
            ->pluck('aggregate_count', 'status')
            ->all();
        $statuses = [];

        foreach (MailDeliveryStatus::cases() as $status) {
            $count = $rawCounts[$status->value] ?? 0;

            if (! is_int($count)) {
                $validatedCount = is_string($count)
                    ? filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
                    : false;
                $count = is_int($validatedCount) ? $validatedCount : 0;
            }

            $statuses[$status->value] = $count;
        }

        $accepted = (clone $query)->whereNotNull('accepted_at')->count();
        $successful = array_sum(array_intersect_key(
            $statuses,
            array_flip(MailNotificationReadQueryBuilder::successfulStatuses()),
        ));
        $failed = array_sum(array_intersect_key(
            $statuses,
            array_flip(MailNotificationReadQueryBuilder::failureStatuses()),
        ));
        $recent = array_values((clone $query)
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(static fn (MailNotification $notification): MailNotificationReadData => MailNotificationReadData::fromModel($notification))
            ->values()
            ->all());

        return new MailNotificationStatistics(
            total: $total,
            statuses: $statuses,
            accepted: $accepted,
            successful: $successful,
            failed: $failed,
            successRate: $total > 0 ? round($successful / $total * 100, 2) : 0.0,
            failureRate: $total > 0 ? round($failed / $total * 100, 2) : 0.0,
            recent: $recent,
        );
    }
}
