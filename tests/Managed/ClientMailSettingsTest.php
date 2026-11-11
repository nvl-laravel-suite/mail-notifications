<?php

declare(strict_types=1);

use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\ClientMailSettings;

it('does not expose client settings the package never enforces', function (): void {
    $client = Client::factory()->withMailSettings([])->create();
    $settings = ClientMailSettings::query()->findOrFail($client->id);

    expect($settings->getFillable())
        ->not->toContain('rate_limit_per_minute')
        ->not->toContain('authentication_mode')
        ->and($settings->getCasts())
        ->not->toHaveKey('rate_limit_per_minute')
        ->not->toHaveKey('authentication_mode');
});
