<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\Integration;

/**
 * Creates isolated Integration fixtures on the configured mail storage.
 *
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     */
    public function definition(): array
    {
        return ['key' => fake()->unique()->slug(), 'name' => fake()->company(), 'icon' => '/images/integrations/smtp.svg', 'capabilities' => []];
    }
}
