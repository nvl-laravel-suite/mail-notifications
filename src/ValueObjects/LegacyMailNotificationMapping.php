<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Validated source mapping for legacy delivery attempts and milestones.
 */
final readonly class LegacyMailNotificationMapping
{
    /**
     * @param  array<string, string>  $columns
     * @param  array<string, string>  $statuses
     * @param  array<string, string|null>  $notifiableTypes
     * @param  array<string, string>  $eventTimestamps
     * @param  list<string>  $metadataAllowlist
     */
    public function __construct(
        public string $table,
        public int $expectedCount,
        public array $columns,
        public array $statuses,
        public array $notifiableTypes,
        public array $eventTimestamps,
        public array $metadataAllowlist,
    ) {}
}
