<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\OwnerMailSettings;

/**
 * Creates isolated Owner Mail Settings fixtures on the configured mail storage.
 *
 * @extends Factory<OwnerMailSettings>
 */
class OwnerMailSettingsFactory extends Factory
{
    protected $model = OwnerMailSettings::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<OwnerMailSettings>, mixed>
     */
    public function definition(): array
    {
        return ['status' => 'active', 'from_name' => fake()->company()];
    }
}
