<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\MailNotifications\ValueObjects\TransitionResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Throwable;

/**
 * Proves that hosts may replace the lifecycle while delegating to package persistence.
 */
final readonly class PluggedTrackingLifecycle implements TrackingLifecycle
{
    /**
     * Create the configured lifecycle fixture.
     */
    public function __construct(
        private DatabaseTrackingLifecycle $database,
    ) {}

    /**
     * Delegate pending tracking persistence.
     */
    public function begin(PreparedMessage $message): TrackingAttempt
    {
        return $this->database->begin($message);
    }

    /**
     * Delegate provider acceptance persistence.
     */
    public function accepted(TrackingAttempt $attempt, ProviderAcceptance $acceptance): void
    {
        $this->database->accepted($attempt, $acceptance);
    }

    /**
     * Delegate local failure persistence.
     */
    public function failed(TrackingAttempt $attempt, Throwable $exception): void
    {
        $this->database->failed($attempt, $exception);
    }

    /**
     * Delegate terminal queued-Mailable failure reconciliation.
     */
    public function queuedFailure(
        PreparedMessage $message,
        Throwable $exception,
    ): void {
        $this->database->queuedFailure($message, $exception);
    }

    /**
     * Delegate verified provider event persistence.
     */
    public function apply(VerifiedDeliveryEvent $event): TransitionResult
    {
        return $this->database->apply($event);
    }
}
