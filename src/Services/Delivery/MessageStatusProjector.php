<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Nvl\MailNotifications\Models\EmailMessage;

/**
 * Projects aggregate message state from independently recorded recipient outcomes.
 *
 * @internal
 */
class MessageStatusProjector
{
    /**
     * Recalculate aggregate message state from persisted recipient observations without losing attempt evidence.
     */
    public function refresh(EmailMessage $message): void
    {
        $statuses = $message->recipients()->get()->map(fn ($recipient): string => $recipient->delivery_status);
        $delivered = $statuses->filter(fn (string $status): bool => $status === 'delivered')->count();
        $terminal = $statuses->isNotEmpty() && $statuses->every(fn (string $status): bool => in_array($status, ['delivered', 'hard_bounced', 'suppressed'], true));
        $message->update([
            'status' => $terminal ? ($delivered === $statuses->count() ? 'delivered' : ($delivered > 0 ? 'partially_delivered' : 'failed')) : 'submitted',
            'completed_at' => $terminal ? ($message->completed_at ?? now()) : null,
        ]);
    }
}
