<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Contracts\DeliveryProfileResolver;
use Nvl\MailNotifications\Exceptions\ScheduledMailException;

/** Reads a deployment-approved delivery profile from Laravel configuration. */
final readonly class ConfiguredDeliveryProfileResolver implements DeliveryProfileResolver
{
    /** Create the deployment configuration reader. */
    public function __construct(private Repository $config) {}

    /** Return the configured mailer while preserving Laravel's default when no override exists. */
    public function resolve(): ?string
    {
        $profile = $this->config->get('mail-notifications.scheduling.delivery_profile');

        if ($profile === null) {
            return null;
        }

        $allowed = $this->config->get('mail-notifications.scheduling.allowed_delivery_profiles', []);

        if (! is_string($profile) || trim($profile) === ''
            || ! is_array($allowed) || ! array_is_list($allowed)
            || ! in_array($profile, $allowed, true)) {
            throw new ScheduledMailException('The configured delivery profile must name an approved Laravel mailer.');
        }

        return $profile;
    }
}
