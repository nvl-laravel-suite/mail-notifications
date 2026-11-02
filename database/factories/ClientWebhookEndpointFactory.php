<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\ClientWebhookEndpoint;

/**
 * Creates isolated Client Webhook Endpoint fixtures on the configured mail storage.
 *
 * @extends Factory<ClientWebhookEndpoint>
 */
class ClientWebhookEndpointFactory extends Factory
{
    protected $model = ClientWebhookEndpoint::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<ClientWebhookEndpoint>, mixed>
     */
    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'url' => 'https://consumer.example/events', 'secret' => ClientWebhookEndpoint::newSecret(), 'events' => ['*'], 'enabled' => true];
    }
}
