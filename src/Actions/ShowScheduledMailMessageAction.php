<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization;
use Nvl\MailNotifications\Enums\ScheduledMailReadAbility;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\ValueObjects\ScheduledMailDetailData;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Resolves one authorized, privacy-bounded scheduled-mail detail projection.
 */
final readonly class ShowScheduledMailMessageAction
{
    public function __construct(
        private ScheduledMailReadAuthorization $authorization,
        private TenantBoundary $boundary,
    ) {}

    public function execute(
        Authenticatable $actor,
        string $id,
    ): ScheduledMailDetailData {
        $message = $this->boundary->query(ScheduledMailMessage::query(), 'mail.scheduled')
            ->select(ScheduledMailDetailData::COLUMNS)
            ->findOrFail($id);
        $this->authorization->authorize(
            ScheduledMailReadAbility::View,
            $actor,
            $message,
        );

        return ScheduledMailDetailData::fromModel($message);
    }
}
