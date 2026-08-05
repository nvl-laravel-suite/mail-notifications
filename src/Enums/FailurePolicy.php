<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/**
 * Defines whether pre-send tracking persistence may block delivery.
 */
enum FailurePolicy: string
{
    case FailClosed = 'fail_closed';
    case FailOpen = 'fail_open';
}
