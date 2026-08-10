<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Database\Eloquent\Builder;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadData;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;

/**
 * Applies the fixed administrative filter allowlist to tracking reads.
 */
final class MailNotificationReadQueryBuilder
{
    /**
     * @return Builder<MailNotification>
     */
    public function build(MailNotificationReadQuery $filters): Builder
    {
        $query = MailNotification::query()->select(MailNotificationReadData::COLUMNS);

        if ($filters->search !== null) {
            $term = "%{$filters->search}%";
            $query->where(static function (Builder $search) use ($term): void {
                $search->where('subject', 'like', $term)
                    ->orWhere('from_email', 'like', $term)
                    ->orWhere('primary_recipient_email', 'like', $term)
                    ->orWhere('provider_message_id', 'like', $term);
            });
        }

        if ($filters->status instanceof MailDeliveryStatus) {
            $query->where('status', $filters->status->value);
        }

        if ($filters->mailer !== null) {
            $query->where('mailer', $filters->mailer);
        }

        if ($filters->messageCategory !== null) {
            $query->where('message_category', $filters->messageCategory);
        }

        if ($filters->from !== null) {
            $query->where('created_at', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->where('created_at', '<=', $filters->to);
        }

        if ($filters->acceptedOnly) {
            $query->whereNotNull('accepted_at');
        }

        if ($filters->failedOnly) {
            $query->whereIn('status', self::failureStatuses());
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    public static function failureStatuses(): array
    {
        return [
            MailDeliveryStatus::Failed->value,
            MailDeliveryStatus::Bounced->value,
            MailDeliveryStatus::Complained->value,
            MailDeliveryStatus::Rejected->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function successfulStatuses(): array
    {
        return [
            MailDeliveryStatus::Accepted->value,
            MailDeliveryStatus::Delivered->value,
            MailDeliveryStatus::Opened->value,
            MailDeliveryStatus::Clicked->value,
        ];
    }
}
