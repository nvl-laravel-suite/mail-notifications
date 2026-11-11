<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/**
 * Names the authentication modes of the original managed client settings.
 *
 * @api
 *
 * @deprecated The package authenticates no client request and no longer reads this mode.
 */
enum ClientAuthenticationMode: string
{
    case Legacy = 'legacy';
    case Dual = 'dual';
    case Broker = 'broker';
}
