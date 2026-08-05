<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use InvalidArgumentException;

/**
 * Describes one bounded remote webhook management outcome without response data.
 */
final readonly class RemoteWebhookManagementResult
{
    public string $provider;

    public string $operation;

    /**
     * @var list<string>
     */
    public array $errors;

    /**
     * Create a sanitized management outcome.
     *
     * @param  list<string>  $errors
     */
    public function __construct(
        string $provider,
        string $operation,
        public int $planned,
        public int $changed,
        public int $unchanged,
        public int $failed,
        public bool $dryRun,
        array $errors = [],
    ) {
        $normalizedProvider = trim($provider);
        $normalizedOperation = trim($operation);

        if ($normalizedProvider === '' || mb_strlen($normalizedProvider) > 128) {
            throw new InvalidArgumentException(
                'Remote webhook results require a bounded provider name.',
            );
        }

        if (! in_array($normalizedOperation, ['sync', 'remove'], true)) {
            throw new InvalidArgumentException(
                'Remote webhook results require a supported operation.',
            );
        }

        if ($planned < 0
            || $changed < 0
            || $unchanged < 0
            || $failed < 0) {
            throw new InvalidArgumentException(
                'Remote webhook result counters cannot be negative.',
            );
        }

        if (count($errors) > 20) {
            throw new InvalidArgumentException(
                'Remote webhook results may expose at most 20 sanitized errors.',
            );
        }

        $normalizedErrors = [];

        foreach ($errors as $error) {
            $normalizedError = trim($error);

            if ($normalizedError === '' || mb_strlen($normalizedError) > 255) {
                throw new InvalidArgumentException(
                    'Remote webhook result errors must be bounded strings.',
                );
            }

            $normalizedErrors[] = $normalizedError;
        }

        $this->provider = $normalizedProvider;
        $this->operation = $normalizedOperation;
        $this->errors = $normalizedErrors;
    }

    /**
     * Determine whether every requested provider operation succeeded.
     */
    public function succeeded(): bool
    {
        return $this->failed === 0;
    }
}
