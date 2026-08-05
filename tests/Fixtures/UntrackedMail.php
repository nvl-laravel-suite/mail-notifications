<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Provides a normal Laravel Mailable with no package tracking integration.
 */
final class UntrackedMail extends Mailable
{
    /**
     * Return the fixture delivery envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Untracked message');
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
