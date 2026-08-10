<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Enums\MailNotificationReadAbility;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\MailNotificationReadQueryBuilder;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadData;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadPage;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;

/**
 * Returns one authorized, bounded page of delivery-history projections.
 */
final readonly class ListMailNotificationsAction
{
    public function __construct(
        private MailNotificationReadAuthorization $authorization,
        private MailNotificationReadQueryBuilder $queries,
    ) {}

    public function execute(
        Authenticatable $actor,
        MailNotificationReadQuery $filters,
    ): MailNotificationReadPage {
        $this->authorization->authorize(MailNotificationReadAbility::List, $actor);
        $maximum = config('mail-notifications.management.maximum_per_page', 100);

        if (! is_int($maximum) || $maximum < 1) {
            $maximum = 100;
        }

        $perPage = min($filters->perPage, $maximum);
        $paginator = $this->queries->build($filters)
            ->orderBy($filters->sort, $filters->direction)
            ->orderBy('id', $filters->direction)
            ->paginate(
                perPage: $perPage,
                page: $filters->page,
            );

        return new MailNotificationReadPage(
            items: array_values($paginator->getCollection()
                ->map(static fn (MailNotification $notification): MailNotificationReadData => MailNotificationReadData::fromModel($notification))
                ->values()
                ->all()),
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
        );
    }
}
