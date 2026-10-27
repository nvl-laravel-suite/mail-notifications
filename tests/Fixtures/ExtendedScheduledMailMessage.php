<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Nvl\MailNotifications\Models\ScheduledMailMessage;

/** Adds host presentation without changing native scheduling storage. */
class ExtendedScheduledMailMessage extends ScheduledMailMessage
{
    public function displayFactory(): string
    {
        return strtoupper($this->factory_alias);
    }
}
