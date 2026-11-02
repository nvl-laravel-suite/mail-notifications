<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/** Identifies the recipient, domain or subject criterion of a managed message flag.
 * @api
 */
enum EmailFlagType: string
{
    case User = 'user';
    case Domain = 'domain';
    case Subject = 'subject';
}
