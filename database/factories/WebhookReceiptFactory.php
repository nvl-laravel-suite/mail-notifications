<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\WebhookReceipt;

/**
 * Creates isolated Webhook Receipt fixtures on the configured mail storage.
 *
 * @extends Factory<WebhookReceipt>
 */
class WebhookReceiptFactory extends Factory
{
    protected $model = WebhookReceipt::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<WebhookReceipt>, mixed>
     */
    public function definition(): array
    {
        $payload = ['type' => 'activity.sent', 'created_at' => now()->toIso8601String(), 'data' => ['id' => (string) Str::uuid(), 'message_id' => 'provider-example', 'recipient' => 'reader@example.com']];

        return ['provider_account_id' => ProviderAccount::factory(), 'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'payload' => $payload, 'status' => 'pending', 'verified_at' => now()];
    }
}
