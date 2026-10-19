<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization;
use Nvl\MailNotifications\Contracts\ShowScheduledMailMessageContract;
use Nvl\MailNotifications\Enums\ScheduledMailReadAbility;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\ValueObjects\ScheduledMailDetailData;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Resolves one authorized, privacy-bounded scheduled-mail detail projection.
 *
 * @api
 */
final readonly class ShowScheduledMailMessageAction implements ShowScheduledMailMessageContract
{
    public function __construct(
        private ScheduledMailReadAuthorization $authorization,
        private OwnershipBoundary $boundary,
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
