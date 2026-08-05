<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Enums\FailurePolicy;
use Nvl\MailNotifications\Exceptions\MailTrackingException;

/**
 * Resolves global and per-mailer tracking eligibility.
 */
final readonly class TrackingEligibility
{
    /**
     * Create the tracking eligibility resolver.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Determine whether an opted-in message should be tracked by its mailer.
     */
    public function shouldTrack(?string $mailer): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return ! in_array($this->resolveMailer($mailer), $this->excludedMailers(), true);
    }

    /**
     * Determine whether package tracking behavior is enabled.
     */
    public function enabled(): bool
    {
        $packageEnabled = $this->boolean(
            'mail-notifications.enabled',
            true,
        );
        $trackingEnabled = $this->boolean(
            'mail-notifications.tracking.enabled',
            true,
        );

        return $packageEnabled && $trackingEnabled;
    }

    /**
     * Resolve the effective Laravel mailer name.
     */
    public function resolveMailer(?string $mailer): string
    {
        if (is_string($mailer) && trim($mailer) !== '') {
            return trim($mailer);
        }

        $defaultMailer = $this->config->get('mail.default', 'default');

        return is_string($defaultMailer) && trim($defaultMailer) !== ''
            ? trim($defaultMailer)
            : 'default';
    }

    /**
     * Resolve the configured pre-send failure policy.
     */
    public function failurePolicy(): FailurePolicy
    {
        $configuredPolicy = $this->config->get(
            'mail-notifications.tracking.failure_policy',
            FailurePolicy::FailClosed->value,
        );
        $policy = is_string($configuredPolicy)
            ? FailurePolicy::tryFrom($configuredPolicy)
            : null;

        if (! $policy instanceof FailurePolicy) {
            throw new MailTrackingException(
                'Mail notification failure policy must be [fail_open] or [fail_closed].',
            );
        }

        return $policy;
    }

    /**
     * Return the mailers excluded from otherwise opted-in tracking.
     *
     * @return list<string>
     */
    public function excludedMailers(): array
    {
        $configuredMailers = $this->config->get(
            'mail-notifications.tracking.excluded_mailers',
            [],
        );

        if (! is_array($configuredMailers)) {
            throw new MailTrackingException('Excluded mailers configuration must be an array.');
        }

        $mailers = array_map(
            static fn (mixed $mailer): string => is_string($mailer)
                ? trim($mailer)
                : '',
            $configuredMailers,
        );

        return array_values(array_unique(array_filter(
            $mailers,
            static fn (string $mailer): bool => $mailer !== '',
        )));
    }

    /**
     * Read one tracking switch without unsafe truthy-value coercion.
     */
    private function boolean(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);

        if (! is_bool($value)) {
            throw new MailTrackingException(
                "Mail notification configuration [{$key}] must be a boolean.",
            );
        }

        return $value;
    }
}
