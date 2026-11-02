<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\RecipientSuppression;

/**
 * Creates isolated Recipient Suppression fixtures on the configured mail storage.
 *
 * @extends Factory<RecipientSuppression>
 */
class RecipientSuppressionFactory extends Factory
{
    protected $model = RecipientSuppression::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<RecipientSuppression>, mixed>
     */
    public function definition(): array
    {
        return ['provider_account_id' => ProviderAccount::factory(), 'email' => fake()->unique()->safeEmail(), 'reason' => 'hard_bounce'];
    }
}
