<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Nvl\MailNotifications\Contracts\SensitiveDataRedactor;
use Nvl\MailNotifications\Services\DefaultSensitiveDataRedactor;

/**
 * Marks metadata after applying the package default recursive redaction.
 */
final readonly class PluggedSensitiveDataRedactor implements SensitiveDataRedactor
{
    /**
     * Create the configured fixture redactor.
     */
    public function __construct(
        private DefaultSensitiveDataRedactor $default,
    ) {}

    /**
     * Return redacted metadata marked by the configured implementation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        return [
            ...$this->default->redact($data),
            'plugged_redactor' => true,
        ];
    }
}
