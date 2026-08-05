<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Nvl\MailNotifications\Contracts\TrackableMessage;
use Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Throwable;

/**
 * Proves host failure hooks can compose package and application behavior.
 */
final class QueuedTrackedMailWithFailureHook extends Mailable implements ShouldQueue, TrackableMessage
{
    use Queueable;
    use SerializesModels;
    use TracksMailDelivery;

    public bool $hostFailureHandled = false;

    /**
     * Return the fixture delivery envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Queued tracked host hook');
    }

    /**
     * Return the fixture Markdown content.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail-notifications-tests::message',
        );
    }

    /**
     * Return the fixture tracking context.
     */
    public function trackingContext(): TrackingContext
    {
        return TrackingContext::forCategory('test.queued-host-hook');
    }

    /**
     * Compose package tracking with application-specific failure handling.
     */
    public function failed(?Throwable $exception): void
    {
        $this->recordMailTrackingFailure($exception);
        $this->hostFailureHandled = true;
    }
}
