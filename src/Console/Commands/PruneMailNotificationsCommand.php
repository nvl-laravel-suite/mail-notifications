<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Nvl\MailNotifications\Exceptions\MailRetentionException;
use Nvl\MailNotifications\Services\MailRetentionConfiguration;
use Nvl\MailNotifications\Services\MailRetentionPruner;
use Throwable;

/**
 * Previews or prunes one bounded set of package-owned database history.
 */
final class PruneMailNotificationsCommand extends Command
{
    protected $signature = 'nvl:mail-notifications:prune
        {--dry-run : Count eligible rows without deleting them}
        {--before= : Explicit RFC 3339 cutoff; defaults to configured retention ages}
        {--limit= : Maximum parent rows selected from each data set}';

    protected $description = 'Prune bounded, allowlisted mail notification history';

    /**
     * Run one explicitly host-invoked retention batch.
     */
    public function handle(MailRetentionPruner $pruner): int
    {
        $limit = $this->limitOption();
        $cutoff = $this->cutoffOption();

        if ($limit === false || $cutoff === false) {
            return self::INVALID;
        }

        try {
            $result = $pruner->prune(
                dryRun: (bool) $this->option('dry-run'),
                limit: $limit,
                cutoff: $cutoff,
            );
        } catch (MailRetentionException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error(sprintf(
                'Mail notification pruning failed: %s',
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        $this->components->info(
            $result->dryRun
                ? 'Mail notification retention dry run completed; no rows were deleted.'
                : 'Mail notification pruning completed.',
        );
        $this->line(sprintf(
            'Notification cutoff (UTC): %s',
            $result->notificationCutoff->toIso8601String(),
        ));
        $this->line(
            $result->scheduledMessageCutoff instanceof CarbonImmutable
                ? sprintf(
                    'Scheduled-message cutoff (UTC): %s',
                    $result->scheduledMessageCutoff->toIso8601String(),
                )
                : 'Scheduled-message pruning: disabled',
        );
        $this->line(sprintf(
            'Tracked notifications: %d',
            $result->notificationCount,
        ));
        $this->line(sprintf(
            'Provider events via cascade: %d',
            $result->providerEventCount,
        ));
        $this->line(sprintf(
            'Terminal scheduled messages: %d',
            $result->scheduledMessageCount,
        ));

        return self::SUCCESS;
    }

    /**
     * Parse the optional positive integer limit override.
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

        if ($integer === null
            || $integer < 1
            || $integer > MailRetentionConfiguration::MAXIMUM_LIMIT) {
            $this->components->error(sprintf(
                'The --limit option must be an integer between 1 and %d.',
                MailRetentionConfiguration::MAXIMUM_LIMIT,
            ));

            return false;
        }

        return $integer;
    }

    /**
     * Parse an optional absolute RFC 3339 cutoff and normalize it to UTC.
     */
    private function cutoffOption(): CarbonImmutable|false|null
    {
        $value = $this->rawOption('before');

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            $this->invalidCutoff();

            return false;
        }

        $cutoff = $this->parseRfc3339($value);

        if (! $cutoff instanceof CarbonImmutable
            || $cutoff->isAfter(CarbonImmutable::now('UTC'))) {
            $this->invalidCutoff();

            return false;
        }

        return $cutoff;
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

    /**
     * Parse the accepted RFC 3339 second and microsecond formats.
     */
    private function parseRfc3339(string $value): ?CarbonImmutable
    {
        $timezone = new DateTimeZone('UTC');

        foreach ([
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s.uP',
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:s.u\Z',
        ] as $format) {
            $parsed = DateTimeImmutable::createFromFormat(
                '!'.$format,
                $value,
                $timezone,
            );
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed instanceof DateTimeImmutable
                && ($errors === false
                    || $errors['warning_count'] === 0
                    && $errors['error_count'] === 0)) {
                return CarbonImmutable::instance($parsed)
                    ->setTimezone('UTC');
            }
        }

        return null;
    }

    /**
     * Render the stable invalid-cutoff message.
     */
    private function invalidCutoff(): void
    {
        $this->components->error(
            'The --before option must be a non-future RFC 3339 timestamp with an explicit timezone.',
        );
    }
}
