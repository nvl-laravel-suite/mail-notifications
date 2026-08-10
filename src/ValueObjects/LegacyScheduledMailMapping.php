<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Validated source mapping for legacy scheduled work and terminal history.
 */
final readonly class LegacyScheduledMailMapping
{
    /**
     * @param  array<string, string>  $columns
     * @param  array<string, string>  $statuses
     * @param  array<string, string|null>  $notifiableTypes
     * @param  array<string, array{alias: string, version: int}>  $factories
     * @param  list<string>  $metadataAllowlist
     */
    public function __construct(
        public string $table,
        public int $expectedCount,
        public array $columns,
        public array $statuses,
        public array $notifiableTypes,
        public array $factories,
        public array $metadataAllowlist,
    ) {}
}
