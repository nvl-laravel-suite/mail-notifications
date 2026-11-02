<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\MarketingOptOut;

/**
 * Creates isolated Marketing Opt Out fixtures on the configured mail storage.
 *
 * @extends Factory<MarketingOptOut>
 */
class MarketingOptOutFactory extends Factory
{
    protected $model = MarketingOptOut::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<MarketingOptOut>, mixed>
     */
    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'email' => fake()->unique()->safeEmail(), 'released_at' => null];
    }
}
