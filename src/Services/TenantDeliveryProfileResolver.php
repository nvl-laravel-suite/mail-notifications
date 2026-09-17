<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Exceptions\ScheduledMailException;
use Nvl\Settings\Contracts\SettingRepository;

/** Selects an approved deployment-managed mailer name from tenant Settings. */
final readonly class TenantDeliveryProfileResolver
{
    /** Create the profile selector. */
    public function __construct(private SettingRepository $settings, private Repository $config) {}

    /** Resolve a tenant-selected named Laravel mailer without changing global configuration. */
    public function resolve(): ?string
    {
        $key = $this->config->get('mail-notifications.scheduling.delivery_profile_setting');
        if ($key === null) {
            return null;
        }
        if (! is_string($key) || trim($key) === '') {
            throw new ScheduledMailException('The scheduled mail delivery-profile setting must be a non-empty string or null.');
        }
        if (! $this->settings->has($key)) {
            throw new ScheduledMailException("The scheduled mail delivery-profile setting [{$key}] is not defined.");
        }
        $profile = $this->settings->get($key);
        if ($profile === null || $profile === '') {
            return null;
        }
        if (! is_string($profile)) {
            throw new ScheduledMailException('The tenant delivery profile must resolve to a string or null.');
        }
        $allowed = $this->config->get('mail-notifications.scheduling.allowed_delivery_profiles', []);
        if (! is_array($allowed) || ! array_is_list($allowed)
            || ! in_array($profile, $allowed, true)) {
            throw new ScheduledMailException("Tenant delivery profile [{$profile}] is not approved by deployment configuration.");
        }

        return $profile;
    }
}
