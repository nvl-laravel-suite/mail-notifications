<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\ClientMailSettings;
use Nvl\MailNotifications\Models\Owner;

/**
 * Creates isolated Client fixtures on the configured mail storage.
 *
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * Configure the related owner, client and settings fixtures needed by this record.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Client $identity): void {
            $identity->mailSettings()->firstOrCreate([], ['owner_id' => $identity->owner_id]);
        });
    }

    /**
     * Create explicit mail-setting overrides after the fixture identity is persisted.
     *
     * @param  array<model-property<ClientMailSettings>, mixed>  $settings
     */
    public function withMailSettings(array $settings): static
    {
        return $this->afterCreating(function (Client $identity) use ($settings): void {
            $identity->mailSettings()->updateOrCreate([], ['owner_id' => $identity->owner_id, ...$settings]);
        });
    }

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     */
    public function definition(): array
    {
        return ['owner_id' => Owner::factory(), 'name' => fake()->company(), 'slug' => fake()->unique()->slug(3), 'status' => 'active'];
    }
}
