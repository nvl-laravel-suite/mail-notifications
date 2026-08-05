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

/**
 * Provides a serializable queued Mailable for transport lifecycle tests.
 */
final class QueuedTrackedMail extends Mailable implements ShouldQueue, TrackableMessage
{
    use Queueable;
    use SerializesModels;
    use TracksMailDelivery;

    /**
     * Return the fixture delivery envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Queued tracked message');
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
        return TrackingContext::forCategory('test.queued-message');
    }
}
