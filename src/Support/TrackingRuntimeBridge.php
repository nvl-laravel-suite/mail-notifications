<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Nvl\MailNotifications\Services\TrackingRuntime;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Connects manually instantiated and serialized Mailables to the package runtime.
 */
final class TrackingRuntimeBridge
{
    private static ?TrackingRuntime $runtime = null;

    /**
     * Attach the provider-owned runtime used by Mailable concerns.
     */
    public static function use(TrackingRuntime $runtime): void
    {
        self::$runtime = $runtime;
    }

    /**
     * Detach any runtime retained by a previous application lifecycle.
     */
    public static function clear(): void
    {
        self::$runtime = null;
    }

    /**
     * Determine whether this application booted the tracking capability.
     */
    public static function available(): bool
    {
        return self::$runtime instanceof TrackingRuntime;
    }

    /**
     * Determine whether the selected mailer is eligible before resolving context.
     */
    public static function shouldTrack(?string $mailer): bool
    {
        return self::$runtime?->shouldTrack($mailer) ?? false;
    }

    /**
     * Stage one opted-in Mailable.
     */
    public static function stage(
        TrackingContext $context,
        ?string $mailer,
        ?string $queueReference = null,
    ): ?string {
        return self::$runtime?->stage($context, $mailer, $queueReference);
    }

    /**
     * Associate a built Symfony message with its staged attempt.
     */
    public static function associate(Email $message, string $correlationId): void
    {
        self::$runtime?->associate($message, $correlationId);
    }

    /**
     * Wrap the selected transport for final-message tracking.
     */
    public static function wrapTransport(
        string $correlationId,
        TransportInterface $transport,
    ): TransportInterface {
        return self::$runtime?->wrapTransport($correlationId, $transport)
            ?? $transport;
    }

    /**
     * Handle a selected mailer whose transport cannot be decorated safely.
     */
    public static function unsupportedMailer(): void
    {
        self::$runtime?->unsupportedMailer();
    }

    /**
     * Record a delivery failure without resolving dependencies from the container.
     */
    public static function failed(string $correlationId, Throwable $exception): void
    {
        self::$runtime?->failed($correlationId, $exception);
    }

    /**
     * Record delivery cancellation before the transport was invoked.
     */
    public static function cancelled(
        string $correlationId,
        Throwable $exception,
    ): void {
        self::$runtime?->cancelled($correlationId, $exception);
    }

    /**
     * Reconcile Laravel's terminal queued-Mailable failure.
     */
    public static function queuedFailure(
        TrackingContext $context,
        ?string $mailer,
        string $queueReference,
        Email $message,
        Throwable $exception,
    ): void {
        self::$runtime?->queuedFailure(
            context: $context,
            mailer: $mailer,
            queueReference: $queueReference,
            message: $message,
            exception: $exception,
        );
    }

    /**
     * Announce a queued-failure synchronization error without replacing it.
     */
    public static function queuedFailureSynchronizationFailed(
        string $queueReference,
        Throwable $exception,
    ): void {
        self::$runtime?->queuedFailureSynchronizationFailed(
            $queueReference,
            $exception,
        );
    }
}
