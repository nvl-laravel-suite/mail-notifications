<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Laravel\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Nvl\MailNotifications\Services\TrackingRuntime;
use Nvl\MailNotifications\Support\StagedTracking;
use Nvl\MailNotifications\Support\TrackingHeaders;

/**
 * Begins tracking from the effective message after global recipient interception.
 */
final readonly class TrackMessageBeforeSending
{
    /**
     * Create the pre-send tracking listener.
     */
    public function __construct(
        private TrackingRuntime $runtime,
    ) {}

    /**
     * Persist one opted-in pending attempt immediately before transport.
     */
    public function handle(MessageSending $event): void
    {
        $header = $event->message->getHeaders()->get(TrackingHeaders::CORRELATION);

        if ($header === null) {
            return;
        }

        $correlationId = trim($header->getBodyAsString());
        $staged = $this->runtime->staged($correlationId);

        if (! $staged instanceof StagedTracking) {
            return;
        }

        $mailer = $event->data['mailer'] ?? $staged->mailer;
        $resolvedMailer = is_string($mailer) && trim($mailer) !== ''
            ? trim($mailer)
            : $staged->mailer;

        if (! $this->runtime->shouldTrack($resolvedMailer)) {
            $event->message->getHeaders()->remove(TrackingHeaders::CORRELATION);
            $this->runtime->forget($correlationId);

            return;
        }

        if ($staged->transportWrapped) {
            $this->runtime->prepare(
                correlationId: $correlationId,
                message: $event->message,
                mailer: $resolvedMailer,
            );

            return;
        }

        $this->runtime->prepare(
            correlationId: $correlationId,
            message: $event->message,
            mailer: $resolvedMailer,
        );
        $prepared = $this->runtime->staged($correlationId)?->prepared;

        if ($prepared !== null) {
            $this->runtime->begin($correlationId, $prepared);
        }
    }
}
