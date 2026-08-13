<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Services\ProviderRegistry;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadData;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;

/**
 * Resolves one authorized delivery by its exact registered provider identity.
 *
 * Delegation to ShowMailNotificationAction is deliberate orchestration so the
 * canonical view authorization and privacy-safe detail projection stay shared.
 */
final readonly class ShowMailNotificationByProviderMessageAction
{
    public function __construct(
        private ProviderRegistry $providers,
        private ShowMailNotificationAction $show,
    ) {}

    public function execute(
        Authenticatable $actor,
        ProviderMessageId $messageId,
    ): MailNotificationReadData {
        $this->providers->resolve($messageId->provider);
        $notification = MailNotification::query()
            ->select('id')
            ->where('provider', $messageId->provider)
            ->where('provider_message_id', $messageId->value)
            ->firstOrFail();

        return $this->show->execute($actor, $notification->id);
    }
}
