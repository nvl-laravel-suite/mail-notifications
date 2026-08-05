<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use LogicException;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\MailNotifications\ValueObjects\TransitionResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Throwable;

/**
 * Simulates a custom lifecycle that cannot select one tracked delivery.
 */
final class AmbiguousTrackingLifecycle implements TrackingLifecycle
{
    public int $applyCalls = 0;

    /**
     * Reject unused tracking setup in this webhook-only fixture.
     *
     * @throws LogicException
     */
    public function begin(PreparedMessage $message): TrackingAttempt
    {
        throw new LogicException('Tracking setup is not used by this fixture.');
    }

    /**
     * Reject unused provider acceptance in this webhook-only fixture.
     *
     * @throws LogicException
     */
    public function accepted(
        TrackingAttempt $attempt,
        ProviderAcceptance $acceptance,
    ): void {
        throw new LogicException(
            'Provider acceptance is not used by this fixture.',
        );
    }

    /**
     * Reject unused local failure handling in this webhook-only fixture.
     *
     * @throws LogicException
     */
    public function failed(TrackingAttempt $attempt, Throwable $exception): void
    {
        throw new LogicException(
            'Local failure handling is not used by this fixture.',
        );
    }

    /**
     * Reject unused queued-Mailable failure handling in this fixture.
     *
     * @throws LogicException
     */
    public function queuedFailure(
        PreparedMessage $message,
        Throwable $exception,
    ): void {
        throw new LogicException(
            'Queued failure handling is not used by this fixture.',
        );
    }

    /**
     * Signal one safe ambiguity without mutating tracking state.
     *
     * @throws AmbiguousDeliveryEventException
     */
    public function apply(VerifiedDeliveryEvent $event): TransitionResult
    {
        $this->applyCalls++;

        throw new AmbiguousDeliveryEventException;
    }
}
