<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/** Records the active, suspended or archived state of a managed routing account.
 * @api
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';
}
