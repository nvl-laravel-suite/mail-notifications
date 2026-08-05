<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Nvl\MailNotifications\Contracts\TrackableMessage;
use Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use RuntimeException;

/**
 * Provides a minimal explicitly tracked Mailable for isolated tests.
 */
final class TrackedMail extends Mailable implements TrackableMessage
{
    use TracksMailDelivery;

    private readonly string $trackingCategory;

    private readonly string $messageSubject;

    private readonly bool $throwOnTrackingContext;

    /**
     * @var array<string, mixed>
     */
    private readonly array $trackingMetadata;

    /**
     * Create the tracked fixture Mailable.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $category = 'test.message',
        array $metadata = [],
        string $subject = 'Tracked message',
        bool $throwOnTrackingContext = false,
    ) {
        $this->trackingCategory = $category;
        $this->trackingMetadata = $metadata;
        $this->messageSubject = $subject;
        $this->throwOnTrackingContext = $throwOnTrackingContext;
    }

    /**
     * Return the fixture delivery envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->messageSubject);
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
        if ($this->throwOnTrackingContext) {
            throw new RuntimeException('Tracking context should not be resolved.');
        }

        return TrackingContext::forCategory($this->trackingCategory)
            ->withMetadata($this->trackingMetadata);
    }
}
