<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Database\Eloquent\Builder;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Applies the fixed administrative filter allowlist to tracking reads.
 *
 * @internal
 */
final readonly class MailNotificationReadQueryBuilder
{
    /** Create the tenant-bounded read builder. */
    public function __construct(private OwnershipBoundary $boundary) {}

    /**
     * Apply the validated history query filters to the already authorized tracked-notification read boundary.
     *
     * @return Builder<MailNotification>
     */
    public function build(MailNotificationReadQuery $filters): Builder
    {
        $query = $this->boundary->query(MailModelRegistry::notificationsQuery(), 'mail.notifications')
            ->select(MailHistory::COLUMNS);

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
     * Expose the failure states used consistently by history filters and summary counts.
     *
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
     * Expose the successful states used consistently by history filters and summary counts.
     *
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
