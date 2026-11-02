<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\ClientMailSettings;

/**
 * Creates isolated Client Mail Settings fixtures on the configured mail storage.
 *
 * @extends Factory<ClientMailSettings>
 */
class ClientMailSettingsFactory extends Factory
{
    protected $model = ClientMailSettings::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<ClientMailSettings>, mixed>
     */
    public function definition(): array
    {
        return ['status' => 'active', 'from_name' => fake()->company()];
    }
}
