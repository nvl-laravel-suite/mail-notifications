<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

/**
 * Defines non-sensitive internal correlation headers used by tracked mail.
 */
final class TrackingHeaders
{
    public const string CORRELATION = 'X-Nvl-Mail-Tracking-Id';
}
