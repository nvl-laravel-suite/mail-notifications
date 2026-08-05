<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use InvalidArgumentException;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\ValueObjects\MailNotificationsDoctorCheck;
use Symfony\Component\Mime\Address;

/**
 * Applies an optional environment-aware global test recipient through Laravel Mail.
 */
final readonly class MailTestingInterceptor
{
    /**
     * Create the Laravel mail testing interceptor.
     */
    public function __construct(
        private Application $application,
        private Repository $config,
        private MailManager $mail,
    ) {}

    /**
     * Apply the configured global recipient override when it is safe and enabled.
     */
    public function apply(): void
    {
        if (! $this->packageEnabled()) {
            return;
        }

        $testing = $this->settings();

        if (! $this->settingBoolean($testing, 'enabled', false)) {
            return;
        }

        $environments = $this->environments($testing['environments'] ?? []);
        $respectEnvironment = $this->settingBoolean(
            $testing,
            'respect_environment',
            true,
        );

        if ($respectEnvironment
            && ($environments === []
                || ! $this->application->environment($environments))) {
            return;
        }

        $recipient = $this->recipient($testing);

        $this->config->set('mail.to', [
            'address' => $recipient->getAddress(),
            'name' => $recipient->getName() !== ''
                ? $recipient->getName()
                : null,
        ]);
        $this->mail->forgetMailers();
    }

    /**
     * Inspect test-recipient configuration and production safety without mutation.
     */
    public function inspect(): MailNotificationsDoctorCheck
    {
        try {
            if (! $this->packageEnabled()) {
                return new MailNotificationsDoctorCheck(
                    key: 'configuration.testing',
                    severity: 'warning',
                    passed: true,
                    message: 'Mail testing interception is inactive because the package is disabled.',
                );
            }

            $testing = $this->settings();

            if (! $this->settingBoolean($testing, 'enabled', false)) {
                return new MailNotificationsDoctorCheck(
                    key: 'configuration.testing',
                    severity: 'warning',
                    passed: true,
                    message: 'Mail testing interception is disabled.',
                );
            }

            $this->recipient($testing);
            $configuredEnvironments = $testing['environments'] ?? [];
            $environments = $this->environments($configuredEnvironments);
            $respectEnvironment = $this->settingBoolean(
                $testing,
                'respect_environment',
                true,
            );

            if ($respectEnvironment && ! is_array($configuredEnvironments)) {
                return new MailNotificationsDoctorCheck(
                    key: 'configuration.testing',
                    severity: 'error',
                    passed: false,
                    message: 'Mail testing interception environments must be an array.',
                );
            }

            if ($respectEnvironment && $environments === []) {
                return new MailNotificationsDoctorCheck(
                    key: 'configuration.testing',
                    severity: 'error',
                    passed: false,
                    message: 'Enabled mail testing interception requires a non-empty environment allowlist.',
                );
            }

            $productionRisk = ! $respectEnvironment
                || in_array('production', $environments, true);

            return new MailNotificationsDoctorCheck(
                key: 'configuration.testing',
                severity: 'warning',
                passed: ! $productionRisk,
                message: $productionRisk
                    ? 'Mail testing interception can run in production; require an environment allowlist that excludes production.'
                    : sprintf(
                        'Mail testing interception is restricted to %d non-production environment(s).',
                        count($environments),
                    ),
            );
        } catch (MailTrackingException $exception) {
            return new MailNotificationsDoctorCheck(
                key: 'configuration.testing',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    /**
     * Resolve host mail.testing settings before package fallbacks.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $hostTesting = $this->config->get('mail.testing');

        if (is_array($hostTesting) && $hostTesting !== []) {
            return $this->stringKeyedSettings($hostTesting);
        }

        $packageTesting = $this->config->get('mail-notifications.testing', []);

        return is_array($packageTesting)
            ? $this->stringKeyedSettings($packageTesting)
            : [];
    }

    /**
     * Keep only string-keyed configuration settings.
     *
     * @param  array<array-key, mixed>  $settings
     * @return array<string, mixed>
     */
    private function stringKeyedSettings(array $settings): array
    {
        $normalized = [];

        foreach ($settings as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Determine whether package runtime behavior is enabled.
     */
    private function packageEnabled(): bool
    {
        $enabled = $this->config->get('mail-notifications.enabled', true);

        if (! is_bool($enabled)) {
            throw new MailTrackingException(
                'Mail notification configuration [mail-notifications.enabled] must be a boolean.',
            );
        }

        return $enabled;
    }

    /**
     * Read one testing switch without unsafe truthy-value coercion.
     *
     * @param  array<string, mixed>  $testing
     */
    private function settingBoolean(
        array $testing,
        string $key,
        bool $default,
    ): bool {
        $value = $testing[$key] ?? $default;

        if (! is_bool($value)) {
            throw new MailTrackingException(
                "Mail testing interception [{$key}] must be a boolean.",
            );
        }

        return $value;
    }

    /**
     * Normalize configured environment names.
     *
     * @return list<string>
     */
    private function environments(mixed $environments): array
    {
        if (! is_array($environments)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $environment): string => is_string($environment)
                ? trim($environment)
                : '',
            $environments,
        )));
    }

    /**
     * Validate and normalize the configured interception recipient.
     *
     * @param  array<string, mixed>  $testing
     */
    private function recipient(array $testing): Address
    {
        $address = $testing['to_address'] ?? null;
        $name = $testing['to_name'] ?? null;

        if (! is_string($address)) {
            throw new MailTrackingException(
                'Enabled mail testing interception requires a valid recipient address.',
            );
        }

        try {
            return new Address(
                $address,
                is_string($name) ? $name : '',
            );
        } catch (InvalidArgumentException $exception) {
            throw new MailTrackingException(
                'Enabled mail testing interception requires a valid recipient address.',
                previous: $exception,
            );
        }
    }
}
