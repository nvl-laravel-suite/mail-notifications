<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization;
use Nvl\MailNotifications\Enums\ScheduledMailReadAbility;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\ScheduledMailReadQueryBuilder;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Nvl\MailNotifications\ValueObjects\ScheduledMailListData;
use Nvl\MailNotifications\ValueObjects\ScheduledMailReadQuery;
use Nvl\MailNotifications\ValueObjects\ScheduledMailStatistics;

/**
 * Aggregates authorized scheduled-mail health without loading protected fields.
 */
final readonly class GetScheduledMailStatisticsAction
{
    public function __construct(
        private ScheduledMailReadAuthorization $authorization,
        private ScheduledMailReadQueryBuilder $queries,
    ) {}

    public function execute(
        Authenticatable $actor,
        ScheduledMailReadQuery $filters,
    ): ScheduledMailStatistics {
        $this->authorization->authorize(
            ScheduledMailReadAbility::Statistics,
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

        foreach (ScheduledMailStatus::cases() as $status) {
            $count = $rawCounts[$status->value] ?? 0;

            if (! is_int($count)) {
                $validatedCount = is_string($count)
                    ? filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
                    : false;
                $count = is_int($validatedCount) ? $validatedCount : 0;
            }

            $statuses[$status->value] = $count;
        }

        $due = (clone $query)
            ->where('status', ScheduledMailStatus::Pending->value)
            ->where(
                'available_at',
                '<=',
                DatabaseTimestamp::format(CarbonImmutable::now('UTC')),
            )
            ->count();
        $recent = array_values((clone $query)
            ->select(ScheduledMailListData::COLUMNS)
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(static fn (ScheduledMailMessage $message): ScheduledMailListData => ScheduledMailListData::fromModel($message))
            ->values()
            ->all());

        return new ScheduledMailStatistics(
            total: $total,
            statuses: $statuses,
            due: $due,
            recent: $recent,
        );
    }
}
