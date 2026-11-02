<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\SendingDomain;

/**
 * Creates isolated Sending Domain fixtures on the configured mail storage.
 *
 * @extends Factory<SendingDomain>
 */
class SendingDomainFactory extends Factory
{
    protected $model = SendingDomain::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     */
    public function definition(): array
    {
        return ['provider_account_id' => ProviderAccount::factory(), 'owner_id' => fn (array $attributes) => ProviderAccount::whereKey($attributes['provider_account_id'])->firstOrFail()->owner_id, 'domain' => fake()->unique()->domainName(), 'status' => 'verified', 'verification_source' => 'manual', 'dns_records' => [], 'verified_at' => now()];
    }
}
