<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Enums\EmailFlagType;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\EmailFlagRule;

/**
 * Creates isolated Email Flag Rule fixtures on the configured mail storage.
 *
 * @extends Factory<EmailFlagRule>
 */
class EmailFlagRuleFactory extends Factory
{
    protected $model = EmailFlagRule::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<EmailFlagRule>, mixed>
     */
    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'label' => fake()->word(), 'color' => '#2563eb', 'type' => EmailFlagType::Subject, 'value' => fake()->word(), 'enabled' => true];
    }
}
