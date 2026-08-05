<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\MailNotifications\ValueObjects\TransitionResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use RuntimeException;
use Throwable;

/**
 * Simulates a completely unavailable tracking persistence boundary.
 */
final class UnavailableTrackingLifecycle implements TrackingLifecycle
{
    /**
     * Reject a pre-transport tracking attempt.
     */
    public function begin(PreparedMessage $message): TrackingAttempt
    {
        throw new RuntimeException('Tracking storage is unavailable.');
    }

    /**
     * Reject provider acceptance persistence.
     */
    public function accepted(TrackingAttempt $attempt, ProviderAcceptance $acceptance): void
    {
        throw new RuntimeException('Tracking storage is unavailable.');
    }

    /**
     * Reject local failure persistence.
     */
    public function failed(TrackingAttempt $attempt, Throwable $exception): void
    {
        throw new RuntimeException('Tracking storage is unavailable.');
    }

    /**
     * Reject terminal queued-Mailable failure persistence.
     */
    public function queuedFailure(
        PreparedMessage $message,
        Throwable $exception,
    ): void {
        throw new RuntimeException('Tracking storage is unavailable.');
    }

    /**
     * Reject provider event persistence.
     */
    public function apply(VerifiedDeliveryEvent $event): TransitionResult
    {
        throw new RuntimeException('Tracking storage is unavailable.');
    }
}
