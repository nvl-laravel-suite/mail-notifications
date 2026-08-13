<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Actions;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Enums\MailNotificationReadAbility;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\MailNotificationReadQueryBuilder;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadData;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadPage;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;
use Nvl\MailNotifications\ValueObjects\NotifiableReference;

/**
 * Returns one authorized delivery-history page for an exact notifiable identity.
 */
final readonly class ListMailNotificationsForNotifiableAction
{
    public function __construct(
        private MailNotificationReadAuthorization $authorization,
        private MailNotificationNotifiableTypeRegistry $notifiableTypes,
        private MailNotificationReadQueryBuilder $queries,
    ) {}

    public function execute(
        Authenticatable $actor,
        NotifiableReference $notifiable,
        ?MailNotificationReadQuery $filters = null,
    ): MailNotificationReadPage {
        $this->authorization->authorize(MailNotificationReadAbility::List, $actor);

        if ($this->notifiableTypes->resolve($notifiable->type) === null) {
            throw new DomainException(sprintf(
                'Mail notification notifiable type [%s] is not registered.',
                $notifiable->type,
            ));
        }

        $filters ??= new MailNotificationReadQuery;
        $maximum = config('mail-notifications.management.maximum_per_page', 100);

        if (! is_int($maximum) || $maximum < 1) {
            $maximum = 100;
        }

        $perPage = min($filters->perPage, $maximum);
        $paginator = $this->queries->build($filters)
            ->where('notifiable_type', $notifiable->type)
            ->where('notifiable_id', $notifiable->identifier)
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
