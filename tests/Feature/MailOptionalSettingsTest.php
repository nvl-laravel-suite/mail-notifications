<?php

declare(strict_types=1);

use Nvl\MailNotifications\Contracts\DeliveryProfileResolver;
use Nvl\MailNotifications\Services\TenantDeliveryProfileResolver;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Providers\SettingsServiceProvider;

it('boots mail scheduling without loading the optional Settings provider', function (): void {
    expect(app()->getLoadedProviders()[SettingsServiceProvider::class] ?? false)->toBeFalse()
        ->and(app(DeliveryProfileResolver::class)->resolve())->toBeNull()
        ->and(app(TenantDeliveryProfileResolver::class)->resolve())->toBeNull();
});

it('selects deployment mail profiles from Laravel configuration without Settings', function (): void {
    config()->set('nvl-mail-notifications.scheduling.delivery_profile', 'smtp-test');
    config()->set('nvl-mail-notifications.scheduling.allowed_delivery_profiles', ['smtp-test']);

    expect(app(DeliveryProfileResolver::class)->resolve())->toBe('smtp-test');
});

it('rejects explicitly selecting unavailable Settings', function (): void {
    config()->set('nvl-mail-notifications.integrations.settings', true);

    expect(fn () => app(DeliveryProfileResolver::class))->toThrow(InvalidArgumentException::class, 'requires the loaded provider');
});

it('retains an explicit tenant setting selection as a required optional capability', function (): void {
    config()->set('nvl-mail-notifications.scheduling.delivery_profile_setting', 'mail.profile');

    expect(fn () => app(DeliveryProfileResolver::class))->toThrow(InvalidArgumentException::class, 'requires the loaded provider');
});

it('uses tenant Settings only when its provider is loaded', function (): void {
    app()->register(SettingsServiceProvider::class);
    config()->set('nvl-mail-notifications.scheduling.delivery_profile_setting', 'mail.profile');
    config()->set('nvl-mail-notifications.scheduling.allowed_delivery_profiles', ['smtp-test']);
    $settings = Mockery::mock(SettingRepository::class);
    $settings->shouldReceive('has')->once()->with('mail.profile')->andReturnTrue();
    $settings->shouldReceive('get')->once()->with('mail.profile')->andReturn('smtp-test');
    app()->instance(SettingRepository::class, $settings);

    expect(app(DeliveryProfileResolver::class)->resolve())->toBe('smtp-test');
});

it('uses the configured default when Settings is explicitly disabled', function (): void {
    app()->register(SettingsServiceProvider::class);
    config()->set('nvl-mail-notifications.integrations.settings', false);

    expect(app(DeliveryProfileResolver::class)->resolve())->toBeNull();
});
