<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Nvl\MailNotifications\Actions\AdoptMailNotificationsAction;

/**
 * Plans or applies manifest-driven legacy Mail Notifications adoption.
 */
final class AdoptMailNotificationsCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:mail-notifications:adopt
        {manifest : Absolute or application-relative JSON manifest path}
        {--stage : Rename canonical-name legacy tables before package migrations}
        {--apply : Apply the validated stage or import plan}
        {--format=text : Output text or json}';

    /** @var string */
    protected $description = 'Plan, stage, or apply a reconciled legacy Mail Notifications adoption';

    public function handle(AdoptMailNotificationsAction $adopt): int
    {
        $format = $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException(
                'The nvl:mail-notifications:adopt format must be text or json.',
            );
        }

        $argument = $this->argument('manifest');

        if (trim($argument) === '') {
            throw new InvalidArgumentException('A Mail Notifications adoption manifest is required.');
        }

        $path = str_starts_with($argument, DIRECTORY_SEPARATOR)
            ? $argument
            : base_path($argument);
        $maximum = config('mail-notifications.adoption.maximum_manifest_bytes', 1_048_576);

        if (! is_int($maximum) || $maximum < 1) {
            throw new InvalidArgumentException(
                'mail-notifications.adoption.maximum_manifest_bytes must be a positive integer.',
            );
        }

        $size = is_file($path) ? filesize($path) : false;

        if (! is_int($size) || $size > $maximum) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption manifest [{$path}] is missing or exceeds {$maximum} bytes.",
            );
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption manifest [{$path}] cannot be read.",
            );
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                "Mail Notifications adoption manifest [{$path}] is not valid JSON.",
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(
                'The Mail Notifications adoption manifest must be a JSON object.',
            );
        }

        /** @var array<array-key, mixed> $decoded */
        $result = $adopt->execute(
            $decoded,
            stage: (bool) $this->option('stage'),
            apply: (bool) $this->option('apply'),
        );

        if ($format === 'json') {
            $this->line((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $phase = $result['phase'] ?? null;
            $mode = $result['mode'] ?? null;

            if (! is_string($phase) || ! is_string($mode)) {
                throw new InvalidArgumentException(
                    'Mail Notifications adoption returned an invalid result.',
                );
            }

            $this->components->info(sprintf(
                'Mail Notifications %s %s completed.',
                $phase,
                $mode,
            ));

            if (isset($result['reconciliation']) && is_array($result['reconciliation'])) {
                $rows = [];

                foreach ($result['reconciliation'] as $resource => $counts) {
                    if (! is_array($counts)) {
                        throw new InvalidArgumentException(
                            'Mail Notifications adoption returned invalid reconciliation counts.',
                        );
                    }

                    $rows[] = [
                        (string) $resource,
                        $this->tableCount($counts['expected'] ?? null, '—'),
                        $this->tableCount($counts['source'] ?? $counts['generated'] ?? null, 0),
                        $this->tableCount($counts['imported'] ?? null, 0),
                        $this->tableCount($counts['matched'] ?? null, 0),
                    ];
                }

                $this->table(
                    ['Resource', 'Expected', 'Source/Generated', 'Imported', 'Matched'],
                    $rows,
                );
            }
        }

        return self::SUCCESS;
    }

    private function tableCount(mixed $value, int|string $default): int|string
    {
        return is_int($value) || is_string($value)
            ? $value
            : $default;
    }
}
