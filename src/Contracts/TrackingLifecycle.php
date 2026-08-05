<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException;
use Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\ProviderAcceptance;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\MailNotifications\ValueObjects\TransitionResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Throwable;

/**
 * Defines the provider-neutral persistence lifecycle for tracked mail.
 */
interface TrackingLifecycle
{
    /**
     * Begin tracking before a prepared message reaches its transport.
     */
    public function begin(PreparedMessage $message): TrackingAttempt;

    /**
     * Record successful acceptance by the configured transport or provider.
     */
    public function accepted(TrackingAttempt $attempt, ProviderAcceptance $acceptance): void;

    /**
     * Record a local delivery failure without replacing the original exception.
     */
    public function failed(TrackingAttempt $attempt, Throwable $exception): void;

    /**
     * Reconcile Laravel's terminal queued-Mailable failure idempotently.
     *
     * Implementations must use the message queue reference rather than
     * recipient, subject, or timing heuristics to select an existing attempt.
     */
    public function queuedFailure(PreparedMessage $message, Throwable $exception): void;

    /**
     * Apply one verified provider delivery event idempotently.
     *
     * Implementations must resolve exactly one tracked delivery before mutation.
     *
     * @throws AmbiguousDeliveryEventException When identity matches multiple deliveries.
     * @throws UnmatchedDeliveryEventException When identity matches no delivery.
     */
    public function apply(VerifiedDeliveryEvent $event): TransitionResult;
}
