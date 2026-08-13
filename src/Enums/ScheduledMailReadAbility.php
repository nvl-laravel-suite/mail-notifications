<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/**
 * Administrative read capabilities for scheduled mail.
 */
enum ScheduledMailReadAbility: string
{
    case List = 'list';
    case View = 'view';
    case Statistics = 'statistics';
}
