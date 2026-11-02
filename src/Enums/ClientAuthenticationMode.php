<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/** Names the host-selected authentication mode retained by managed client settings.
 * @api
 */
enum ClientAuthenticationMode: string
{
    case Legacy = 'legacy';
    case Dual = 'dual';
    case Broker = 'broker';
}
