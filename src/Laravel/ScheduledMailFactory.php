<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Laravel;

use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;

/**
 * Decorates the selected mailer for one scheduled delivery only.
 *
 * @internal
 */
final readonly class ScheduledMailFactory implements Factory
{
    /**
     * Preserve the host factory and the persisted recipient envelope.
     */
    public function __construct(
        private Factory $factory,
        private ScheduledRecipients $recipients,
        private ?string $deliveryProfile = null,
    ) {}

    /**
     * Resolve the host-selected mailer with final callback recipient enforcement.
     */
    public function mailer(mixed $name = null): Mailer
    {
        return new ScheduledRecipientMailer(
            $this->factory->mailer($this->deliveryProfile ?? $name),
            $this->recipients,
        );
    }
}
