<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Nvl\MailNotifications\ValueObjects\ScheduledMailReadQuery;

/**
 * Applies the fixed administrative filter allowlist to scheduled-mail reads.
 */
final readonly class ScheduledMailReadQueryBuilder
{
    public function __construct(
        private MailNotificationNotifiableTypeRegistry $notifiableTypes,
    ) {}

    /**
     * @return Builder<ScheduledMailMessage>
     */
    public function build(ScheduledMailReadQuery $filters): Builder
    {
        $query = ScheduledMailMessage::query();

        if ($filters->status instanceof ScheduledMailStatus) {
            $query->where('status', $filters->status->value);
        }

        if ($filters->factoryAlias !== null) {
            $query->where('factory_alias', $filters->factoryAlias);
        }

        if ($filters->notifiable !== null) {
            if ($this->notifiableTypes->resolve($filters->notifiable->type) === null) {
                throw new DomainException(sprintf(
                    'Mail notification notifiable type [%s] is not registered.',
                    $filters->notifiable->type,
                ));
            }

            $query->where('notifiable_type', $filters->notifiable->type)
                ->where('notifiable_id', $filters->notifiable->identifier);
        }

        if ($filters->from !== null) {
            $query->where('scheduled_for', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->where('scheduled_for', '<=', $filters->to);
        }

        if ($filters->dueOnly) {
            $query->where('status', ScheduledMailStatus::Pending->value)
                ->where(
                    'available_at',
                    '<=',
                    DatabaseTimestamp::format(CarbonImmutable::now('UTC')),
                );
        }

        return $query;
    }
}
