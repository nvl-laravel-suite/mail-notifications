<?php

declare(strict_types=1);

use Nvl\MailNotifications\Tenancy\MailNotificationsAdoptionAdapter;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

it('registers the scheduled and tracked mail adopter', function (): void {
    expect(app(TenantAdoptionRegistry::class)->all()['mail-notifications'] ?? null)->toBe(MailNotificationsAdoptionAdapter::class);
});
