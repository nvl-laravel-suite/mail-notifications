<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Seeders;

use Illuminate\Database\Seeder;
use Nvl\MailNotifications\Models\Integration;
use Nvl\MailNotifications\Services\Integrations\ProviderApiManager;

/**
 * Seeds the supported provider catalogue and capability metadata without creating account credentials.
 */
class IntegrationSeeder extends Seeder
{
    /**
     * Upsert reviewed provider names, marks and capabilities without changing account credentials.
     */
    public function run(): void
    {
        foreach (['mailersend' => 'MailerSend', 'sendgrid' => 'SendGrid', 'mailgun' => 'Mailgun', 'smtp' => 'SMTP'] as $key => $name) {
            Integration::firstOrCreate(['key' => $key], ['name' => $name, 'icon' => '/images/integrations/'.$key.'.svg', 'capabilities' => app(ProviderApiManager::class)->capabilities($key)]);
        }
    }
}
