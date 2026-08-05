<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\TrackingAttempt;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Symfony\Component\Mime\Email;

/**
 * Holds transient in-process state between Mailable preparation and mail events.
 */
final class StagedTracking
{
    /**
     * Create transient tracking state.
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly string $mailer,
        public readonly TrackingContext $context,
        public readonly ?string $queueReference = null,
        public ?TrackingAttempt $attempt = null,
        public ?PreparedMessage $prepared = null,
        public bool $beginFailed = false,
        public bool $transportWrapped = false,
        public ?Email $message = null,
    ) {}
}
