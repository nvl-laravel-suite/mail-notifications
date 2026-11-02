<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\Owner;
use Nvl\MailNotifications\Models\ProviderAccount;

/**
 * Creates isolated Provider Account fixtures on the configured mail storage.
 *
 * @extends Factory<ProviderAccount>
 */
class ProviderAccountFactory extends Factory
{
    protected $model = ProviderAccount::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     */
    public function definition(): array
    {
        return ['owner_id' => Owner::factory(), 'name' => fake()->unique()->words(3, true), 'provider' => 'mailersend', 'status' => 'active', 'credentials' => ['api_key' => 'test-provider-key'], 'webhook_secret' => 'test-webhook-secret'];
    }
}
