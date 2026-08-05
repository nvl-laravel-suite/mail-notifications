<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Provides a minimal Mailable rebuilt by scheduled-message fixtures.
 */
final class ScheduledTestMail extends Mailable
{
    /**
     * Create the scheduled fixture Mailable.
     */
    public function __construct(
        public readonly string $body,
    ) {}

    /**
     * Return the fixture delivery envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Scheduled test message');
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
}
