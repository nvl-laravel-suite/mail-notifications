<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Describes the effective message observed immediately before transport.
 */
final readonly class PreparedMessage
{
    /**
     * Create a prepared provider-neutral message.
     *
     * @param  list<Recipient>  $to
     * @param  list<Recipient>  $cc
     * @param  list<Recipient>  $bcc
     */
    public function __construct(
        public string $correlationId,
        public string $mailer,
        public TrackingContext $context,
        public ?Recipient $from,
        public array $to,
        public array $cc = [],
        public array $bcc = [],
        public ?string $subject = null,
        public ?string $queueReference = null,
    ) {}
}
