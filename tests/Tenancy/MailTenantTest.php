<?php

declare(strict_types=1);

use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantResourceRegistry;

it('registers scheduled mail and tracking as mixed roots with inherited events', function (): void {
    $resources = app(TenantResourceRegistry::class);

    expect($resources->get('mail.scheduled')->model)->toBe(ScheduledMailMessage::class)
        ->and($resources->get('mail.scheduled')->allowsPlatformRows)->toBeTrue()
        ->and($resources->get('mail.notifications')->model)->toBe(MailNotification::class)
        ->and($resources->get('mail.notifications')->allowsPlatformRows)->toBeTrue()
        ->and($resources->get('mail.events')->model)->toBe(MailNotificationEvent::class)
        ->and($resources->get('mail.events')->kind)->toBe(TenantResourceKind::Inherited)
        ->and($resources->get('mail.events')->parentResource)->toBe('mail.notifications');
});

it('keeps provider credentials deployment managed and exposes only a named profile selector', function (): void {
    expect(config('mail-notifications.scheduling.delivery_profile_setting'))->toBeNull()
        ->and(config('mail-notifications.scheduling.allowed_delivery_profiles'))->toBe([])
        ->and(config('mail-notifications.providers'))->toBeArray();
});
