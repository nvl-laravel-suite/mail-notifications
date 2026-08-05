<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Nvl\MailNotifications\Exceptions\MailRetentionException;
use Nvl\MailNotifications\Services\MailAnonymizationConfiguration;
use Nvl\MailNotifications\Services\MailHistoryAnonymizer;
use Throwable;

/**
 * Previews or anonymizes one bounded set of retained mail history.
 */
final class AnonymizeMailNotificationsCommand extends Command
{
    protected $signature = 'nvl:mail-notifications:anonymize
        {--dry-run : Count eligible rows without anonymizing them}
        {--before= : Explicit RFC 3339 cutoff; defaults to configured anonymization ages}
        {--limit= : Maximum rows selected independently from each data set}';

    protected $description = 'Anonymize bounded mail notification history without deleting lifecycle rows';

    /**
     * Run one explicitly host-invoked anonymization stage.
     */
    public function handle(MailHistoryAnonymizer $anonymizer): int
    {
        $limit = $this->limitOption();
        $cutoff = $this->cutoffOption();

        if ($limit === false || $cutoff === false) {
            return self::INVALID;
        }

        try {
            $result = $anonymizer->anonymize(
                dryRun: (bool) $this->option('dry-run'),
                limit: $limit,
                cutoff: $cutoff,
            );
        } catch (MailRetentionException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error(sprintf(
                'Mail notification anonymization failed: %s',
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        $this->components->info(
            $result->dryRun
                ? 'Mail notification anonymization dry run completed; no rows were changed.'
                : 'Mail notification anonymization completed.',
        );
        $this->line(sprintf(
            'Notification and provider-event cutoff (UTC): %s',
            $result->notificationCutoff->toIso8601String(),
        ));
        $this->line(
            $result->scheduledMessageCutoff instanceof CarbonImmutable
                ? sprintf(
                    'Scheduled-message cutoff (UTC): %s',
                    $result->scheduledMessageCutoff->toIso8601String(),
                )
                : 'Scheduled-message anonymization: disabled',
        );
        $this->line(sprintf(
            'Tracked notifications: %d',
            $result->notificationCount,
        ));
        $this->line(sprintf(
            'Provider events: %d',
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
            || $integer > MailAnonymizationConfiguration::MAXIMUM_LIMIT) {
            $this->components->error(sprintf(
                'The --limit option must be an integer between 1 and %d.',
                MailAnonymizationConfiguration::MAXIMUM_LIMIT,
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
