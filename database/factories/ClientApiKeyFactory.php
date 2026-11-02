<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\ClientApiKey;

/**
 * Creates isolated Client Api Key fixtures on the configured mail storage.
 *
 * @extends Factory<ClientApiKey>
 */
class ClientApiKeyFactory extends Factory
{
    protected $model = ClientApiKey::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     */
    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'name' => 'Integration', 'prefix' => 'mn_'.Str::random(12), 'token_hash' => hash('sha256', Str::random(64)), 'abilities' => ['*']];
    }
}
