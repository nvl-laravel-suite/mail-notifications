<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Enums;

/** Closed maintenance purposes requiring independently authorized owner system admission. @api */
enum MailOwnerOperation: string
{
    case ProcessScheduled = 'mail.process-scheduled';
    case RecoverScheduled = 'mail.recover-scheduled';
    case Prune = 'mail.prune';
    case Anonymize = 'mail.anonymize';
}
