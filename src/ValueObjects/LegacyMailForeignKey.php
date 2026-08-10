<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

/**
 * Explicit host foreign key detached during staging and restored after import.
 */
final readonly class LegacyMailForeignKey
{
    public function __construct(
        public string $table,
        public string $column,
        public string $name,
        public string $onDelete,
    ) {}
}
