<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Enums\MailNotificationReadAbility;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadData;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Resolves one authorized delivery and its metadata-free provider events.
 */
final readonly class ShowMailNotificationAction
{
    public function __construct(private MailNotificationReadAuthorization $authorization, private TenantBoundary $boundary) {}

    public function execute(
        Authenticatable $actor,
        string $id,
    ): MailNotificationReadData {
        $notification = $this->boundary->query(MailNotification::query(), 'mail.notifications')
            ->select(MailNotificationReadData::COLUMNS)
            ->findOrFail($id);
        $this->authorization->authorize(
            MailNotificationReadAbility::View,
            $actor,
            $notification,
        );
        $notification->setRelation(
            'providerEvents',
            $notification->providerEvents()
                ->select([
                    'id',
                    'mail_notification_id',
                    'provider',
                    'provider_event_id',
                    'provider_message_id',
                    'normalized_type',
                    'occurred_at',
                    'processed_at',
                    'redacted_at',
                ])
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get(),
        );

        return MailNotificationReadData::fromModel($notification);
    }
}
