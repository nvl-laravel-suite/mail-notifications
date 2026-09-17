<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tenancy;

use Closure;
use Nvl\MailNotifications\Services\TrackingRuntime;
use Nvl\Tenancy\Contracts\TenantContextParticipant;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;

/** Prevents transient tracking objects from crossing sequential tenant operations. */
final readonly class MailTrackingContextParticipant implements TenantContextParticipant
{
    /** Create the lifecycle participant. */
    public function __construct(private TrackingRuntime $runtime) {}

    /** Clear both entry and restoration scopes; transient mail state is never portable. */
    public function enter(TenantContextSnapshot $next): Closure
    {
        $this->runtime->clear();

        return function (): void {
            $this->runtime->clear();
        };
    }
}
