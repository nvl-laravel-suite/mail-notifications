<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/**
 * Administrative read capabilities delegated to the consuming application.
 */
enum MailNotificationReadAbility: string
{
    case List = 'list';
    case View = 'view';
    case Statistics = 'statistics';
    case Suggest = 'suggest';
}
