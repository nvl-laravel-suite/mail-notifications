<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\Owner;
use Nvl\MailNotifications\Models\OwnerMailSettings;

/**
 * Creates isolated Owner fixtures on the configured mail storage.
 *
 * @extends Factory<Owner>
 */
class OwnerFactory extends Factory
{
    protected $model = Owner::class;

    /**
     * Configure the related owner, client and settings fixtures needed by this record.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Owner $identity): void {
            $identity->mailSettings()->firstOrCreate([], []);
        });
    }

    /**
     * Create explicit mail-setting overrides after the fixture identity is persisted.
     *
     * @param  array<model-property<OwnerMailSettings>, mixed>  $settings
     */
    public function withMailSettings(array $settings): static
    {
        return $this->afterCreating(function (Owner $identity) use ($settings): void {
            $identity->mailSettings()->updateOrCreate([], [...$settings]);
        });
    }

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     */
    public function definition(): array
    {
        return ['name' => fake()->company(), 'slug' => fake()->unique()->slug(3), 'status' => 'active'];
    }
}
