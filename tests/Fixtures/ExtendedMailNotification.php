<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Nvl\MailNotifications\Models\MailNotification;

/** Adds host presentation without changing native delivery storage. */
class ExtendedMailNotification extends MailNotification
{
    public function displayCategory(): string
    {
        return strtoupper($this->message_category ?? '');
    }
}
