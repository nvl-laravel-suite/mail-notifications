<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Ownership;

use Closure;
use Nvl\MailNotifications\Services\TrackingRuntime;
use Nvl\Support\Ownership\Contracts\OwnershipContextParticipant;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

/** Prevents transient tracking objects from crossing sequential tenant operations. */
final readonly class MailTrackingContextParticipant implements OwnershipContextParticipant
{
    /** Create the lifecycle participant. */
    public function __construct(private TrackingRuntime $runtime) {}

    /** Clear both entry and restoration scopes; transient mail state is never portable. */
    public function enter(OwnershipSnapshot $next): Closure
    {
        $this->runtime->clear();

        return function (): void {
            $this->runtime->clear();
        };
    }
}
