<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\SenderIdentity;

/**
 * Creates isolated Sender Identity fixtures on the configured mail storage.
 *
 * @extends Factory<SenderIdentity>
 */
class SenderIdentityFactory extends Factory
{
    protected $model = SenderIdentity::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     */
    public function definition(): array
    {
        return ['provider_account_id' => ProviderAccount::factory(), 'owner_id' => fn (array $attributes) => ProviderAccount::whereKey($attributes['provider_account_id'])->firstOrFail()->owner_id, 'email' => fake()->unique()->safeEmail(), 'name' => fake()->company(), 'verified_at' => now()];
    }
}
