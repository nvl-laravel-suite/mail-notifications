<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Traits;

use Nvl\Support\Ownership\Traits\HasOwnership;

/** Applies the canonical ownership boundary to persisted mail records. @internal */
trait HasMailOwnership
{
    use HasOwnership;
}
