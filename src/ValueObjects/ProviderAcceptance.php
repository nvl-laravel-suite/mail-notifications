<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Describes successful transport acceptance without exposing provider SDK types.
 */
final readonly class ProviderAcceptance
{
    /**
     * Create a provider acceptance result.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ProviderMessageId $messageId,
        public array $metadata = [],
    ) {}
}
