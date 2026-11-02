<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\SenderIdentity;

/**
 * Creates isolated Email Message fixtures on the configured mail storage.
 *
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<EmailMessage>, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'provider_account_id' => function (array $attributes): string {
                $client = Client::query()->whereKey($attributes['client_id'])->firstOrFail();

                return $client->effectiveMailSettings()['provider_account_id'] ?? ProviderAccount::factory()->create(['owner_id' => $client->owner_id])->id;
            },
            'sender_identity_id' => fn (array $attributes): string => SenderIdentity::query()->firstOrCreate(
                ['provider_account_id' => $attributes['provider_account_id'], 'email' => $attributes['from_email']],
                ['owner_id' => Client::query()->whereKey($attributes['client_id'])->firstOrFail()->owner_id, 'verified_at' => now(), 'verification_source' => 'manual', 'status' => 'verified'],
            )->id,
            'type' => 'transactional', 'category' => null, 'language' => null,
            'status' => 'accepted', 'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::random(32)),
            'from_email' => 'sender@example.com', 'from_name' => 'Sender', 'subject' => fake()->sentence(), 'html' => '<p>Example email</p>', 'text' => 'Example email',
            'data' => [], 'metadata' => [], 'tags' => [],
        ];
    }
}
