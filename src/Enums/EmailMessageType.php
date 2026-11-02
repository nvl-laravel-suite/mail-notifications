<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/** Classifies transactional and marketing mail for delivery policy.
 * @api
 */
enum EmailMessageType: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';
}
