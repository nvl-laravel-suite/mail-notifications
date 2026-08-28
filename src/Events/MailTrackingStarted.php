<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;

/**
 * Announces that a provider-neutral tracking attempt was persisted.
 */
final class MailTrackingStarted
{
    use Dispatchable;

    /**
     * Create the tracking-started event.
     *
     * @param  array<string, string|int|bool|null>  $correlation
     */
    public function __construct(
        public readonly TrackingAttempt $attempt,
        public readonly string $category,
        public readonly array $correlation = [],
    ) {}
}
