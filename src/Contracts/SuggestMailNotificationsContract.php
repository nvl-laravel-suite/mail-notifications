<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\ValueObjects\MailNotificationSuggestion;

/**
 * Defines the consumer-facing SuggestMailNotificationsAction workflow.
 *
 * @api
 */
interface SuggestMailNotificationsContract
{
    /**
     * @return list<MailNotificationSuggestion>
     */
    public function execute(
        Authenticatable $actor,
        string $search,
        ?MailDeliveryStatus $status = null,
        ?string $mailer = null,
        ?int $limit = null,
    ): array;
}
