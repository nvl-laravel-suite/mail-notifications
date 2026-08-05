<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use InvalidArgumentException;

/**
 * Represents an authenticated provider webhook intentionally acknowledged without mutation.
 */
final readonly class WebhookAcknowledgement
{
    public string $provider;

    public string $event;

    public string $reason;

    /**
     * Create a bounded provider webhook acknowledgement.
     */
    public function __construct(
        string $provider,
        string $event,
        string $reason,
    ) {
        $normalizedProvider = trim($provider);
        $normalizedEvent = trim($event);
        $normalizedReason = trim($reason);

        if ($normalizedProvider === ''
            || $normalizedEvent === ''
            || $normalizedReason === '') {
            throw new InvalidArgumentException(
                'Webhook acknowledgements require provider, event, and reason values.',
            );
        }

        if (mb_strlen($normalizedProvider) > 128
            || mb_strlen($normalizedEvent) > 128
            || mb_strlen($normalizedReason) > 128) {
            throw new InvalidArgumentException(
                'Webhook acknowledgement values exceed package limits.',
            );
        }

        $this->provider = $normalizedProvider;
        $this->event = $normalizedEvent;
        $this->reason = $normalizedReason;
    }
}
