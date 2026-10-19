<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Nvl\MailNotifications\Enums\MailOwnerOperation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Enumerates explicit stored owners without granting access or depending on a tenant directory. @api */
interface MailOwnerWorklist
{
    /** @return iterable<OwnerReference> */
    public function owners(MailOwnerOperation $operation): iterable;
}
