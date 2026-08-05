<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Laravel\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Nvl\MailNotifications\Services\TrackingRuntime;
use Nvl\MailNotifications\Support\StagedTracking;
use Nvl\MailNotifications\Support\TrackingHeaders;
use Nvl\MailNotifications\ValueObjects\TransportResult;

/**
 * Records provider-neutral acceptance after Laravel completes transport delivery.
 */
final readonly class TrackMessageAfterSending
{
    /**
     * Create the post-send tracking listener.
     */
    public function __construct(
        private TrackingRuntime $runtime,
    ) {}

    /**
     * Persist transport acceptance for one opted-in message.
     */
    public function handle(MessageSent $event): void
    {
        $message = $event->message;
        $header = $message->getHeaders()->get(TrackingHeaders::CORRELATION);

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

        $this->runtime->accepted(
            $correlationId,
            new TransportResult(
                mailer: $resolvedMailer,
                message: $event->sent,
            ),
        );
    }
}
