<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization;
use Nvl\MailNotifications\Enums\ScheduledMailReadAbility;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\ScheduledMailReadQueryBuilder;
use Nvl\MailNotifications\ValueObjects\ScheduledMailListData;
use Nvl\MailNotifications\ValueObjects\ScheduledMailReadPage;
use Nvl\MailNotifications\ValueObjects\ScheduledMailReadQuery;

/**
 * Returns one authorized, bounded page of safe scheduled-mail projections.
 */
final readonly class ListScheduledMailMessagesAction
{
    public function __construct(
        private ScheduledMailReadAuthorization $authorization,
        private ScheduledMailReadQueryBuilder $queries,
    ) {}

    public function execute(
        Authenticatable $actor,
        ScheduledMailReadQuery $filters,
    ): ScheduledMailReadPage {
        $this->authorization->authorize(ScheduledMailReadAbility::List, $actor);
        $maximum = config(
            'mail-notifications.management.scheduled_maximum_per_page',
            100,
        );

        if (! is_int($maximum) || $maximum < 1) {
            $maximum = 100;
        }

        $perPage = min($filters->perPage, $maximum);
        $paginator = $this->queries->build($filters)
            ->select(ScheduledMailListData::COLUMNS)
            ->orderBy($filters->sort, $filters->direction)
            ->orderBy('id', $filters->direction)
            ->paginate(
                perPage: $perPage,
                page: $filters->page,
            );

        return new ScheduledMailReadPage(
            items: array_values($paginator->getCollection()
                ->map(static fn (ScheduledMailMessage $message): ScheduledMailListData => ScheduledMailListData::fromModel($message))
                ->values()
                ->all()),
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
        );
    }
}
