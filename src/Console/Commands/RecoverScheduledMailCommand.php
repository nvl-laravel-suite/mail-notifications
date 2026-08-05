<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use Nvl\MailNotifications\Services\ScheduledMailConfiguration;
use Nvl\MailNotifications\Services\ScheduledMailRecovery;

/**
 * Recovers one bounded batch of expired scheduled-mail claims.
 */
final class RecoverScheduledMailCommand extends Command
{
    protected $signature = 'nvl:mail-notifications:recover-scheduled
        {--limit= : Maximum expired claims to recover in this run}';

    protected $description = 'Recover a bounded batch of expired scheduled-mail claims';

    /**
     * Run one host-scheduled recovery batch.
     */
    public function handle(
        ScheduledMailConfiguration $configuration,
        ScheduledMailRecovery $recovery,
    ): int {
        if (! $configuration->enabled()) {
            $this->components->info('Scheduled mail is disabled.');

            return self::SUCCESS;
        }

        $limit = $this->limitOption();

        if ($limit === false) {
            return self::INVALID;
        }

        $recovered = $recovery->recover($limit);
        $this->components->info(sprintf(
            'Recovered %d scheduled mail claim(s).',
            $recovered,
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
