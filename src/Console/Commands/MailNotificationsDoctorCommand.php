<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Console\Commands;

use Illuminate\Console\Command;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\ValueObjects\MailNotificationsDoctorCheck;

/**
 * Reports Mail Notifications package readiness without mutation.
 */
final class MailNotificationsDoctorCommand extends Command
{
    protected $signature = 'nvl:mail-notifications:doctor
        {--strict : Treat warnings as failures}
        {--format=text : Output format: text or json}';

    protected $description = 'Inspect Mail Notifications configuration and schema readiness';

    /**
     * Run the non-mutating package readiness checks.
     */
    public function handle(MailNotificationsDoctor $doctor): int
    {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            $this->components->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $checks = $doctor->inspect();
        $strict = (bool) $this->option('strict');
        $failed = collect($checks)->contains(
            static fn (MailNotificationsDoctorCheck $check): bool => ! $check->passed
                && ($strict || $check->severity === 'error'),
        );

        if ($format === 'json') {
            $this->line((string) json_encode([
                'healthy' => ! $failed,
                'checks' => array_map(
                    static fn (MailNotificationsDoctorCheck $check): array => [
                        'key' => $check->key,
                        'severity' => $check->severity,
                        'passed' => $check->passed,
                        'message' => $check->message,
                    ],
                    $checks,
                ),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $check) {
                $this->line(sprintf(
                    '[%s] %s: %s',
                    $check->passed ? 'PASS' : strtoupper($check->severity),
                    $check->key,
                    $check->message,
                ));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
