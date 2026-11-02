<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\ProviderSyncRun;

/**
 * Creates isolated Provider Sync Run fixtures on the configured mail storage.
 *
 * @extends Factory<ProviderSyncRun>
 */
class ProviderSyncRunFactory extends Factory
{
    protected $model = ProviderSyncRun::class;

    /** Represent a finished domain/sender run with no active account claim. */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'completed', 'phase' => 'senders', 'domains_status' => 'completed', 'senders_status' => 'completed', 'active_provider_account_id' => null, 'started_at' => now(), 'finished_at' => now()]);
    }

    /** Represent terminal provider read denial without retaining an active account claim. */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'failed', 'domains_status' => 'failed', 'active_provider_account_id' => null, 'started_at' => now(), 'finished_at' => now(), 'error_code' => 'permission_denied', 'error_message' => 'The provider denied read access.']);
    }

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<ProviderSyncRun>, mixed>
     */
    public function definition(): array
    {
        return ['provider_account_id' => ProviderAccount::factory(), 'active_provider_account_id' => fn (array $attributes) => $attributes['provider_account_id'], 'queue_connection' => 'database', 'configuration_fingerprint' => hash('sha256', 'factory-run')];
    }
}
