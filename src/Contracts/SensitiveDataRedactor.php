<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

/**
 * Redacts sensitive nested values before persistence or operational output.
 */
interface SensitiveDataRedactor
{
    /**
     * Return a recursively redacted copy of the supplied data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array;
}
