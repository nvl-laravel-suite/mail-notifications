<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use Nvl\MailNotifications\Services\ScheduledMailConfiguration;
use Nvl\MailNotifications\Services\ScheduledMailProcessor;

/**
 * Processes one bounded batch of due scheduled mail.
 */
final class ProcessScheduledMailCommand extends Command
{
    protected $signature = 'nvl:mail-notifications:process-scheduled
        {--limit= : Maximum due messages to process in this run}';

    protected $description = 'Process a bounded batch of due scheduled mail';

    /**
     * Run one host-scheduled processing batch.
     */
    public function handle(
        ScheduledMailConfiguration $configuration,
        ScheduledMailProcessor $processor,
    ): int {
        if (! $configuration->enabled()) {
            $this->components->info('Scheduled mail is disabled.');

            return self::SUCCESS;
        }

        $limit = $this->limitOption();

        if ($limit === false) {
            return self::INVALID;
        }

        $processed = $processor->process($limit);
        $this->components->info(sprintf(
            'Processed %d scheduled mail claim(s).',
            $processed,
        ));

        return self::SUCCESS;
    }

    /**
     * Parse the optional positive integer batch override.
     */
    private function limitOption(): int|false|null
    {
        $limit = $this->rawOption('limit');

        if ($limit === null) {
            return null;
        }

        $integer = match (true) {
            is_int($limit) => $limit,
            is_string($limit)
                && preg_match('/\A[0-9]+\z/D', $limit) === 1 => (int) $limit,
            default => null,
        };

        if ($integer !== null
            && $integer >= 1
            && $integer <= 1_000) {
            return $integer;
        }

        $this->components->error(
            'The --limit option must be an integer between 1 and 1000.',
        );

        return false;
    }

    /**
     * Read an option without relying on Symfony's narrower CLI-only PHPDoc.
     *
     * Laravel's command test API may provide native scalar values.
     */
    private function rawOption(string $name): mixed
    {
        return $this->option($name);
    }
}
