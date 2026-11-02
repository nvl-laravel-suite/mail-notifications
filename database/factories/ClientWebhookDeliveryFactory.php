<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Models\ClientWebhookDelivery;
use Nvl\MailNotifications\Models\ClientWebhookEndpoint;

/**
 * Creates isolated Client Webhook Delivery fixtures on the configured mail storage.
 *
 * @extends Factory<ClientWebhookDelivery>
 */
class ClientWebhookDeliveryFactory extends Factory
{
    protected $model = ClientWebhookDelivery::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<ClientWebhookDelivery>, mixed>
     */
    public function definition(): array
    {
        return ['client_webhook_endpoint_id' => ClientWebhookEndpoint::factory(), 'event_id' => (string) Str::uuid(), 'event_key' => (string) Str::uuid(), 'event_type' => 'message.delivered', 'payload' => '{}', 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now()];
    }
}
