<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\EmailRecipient;

/**
 * Creates isolated Email Recipient fixtures on the configured mail storage.
 *
 * @extends Factory<EmailRecipient>
 */
class EmailRecipientFactory extends Factory
{
    protected $model = EmailRecipient::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<EmailRecipient>, mixed>
     */
    public function definition(): array
    {
        return ['email_message_id' => EmailMessage::factory(), 'type' => 'to', 'email' => fake()->unique()->safeEmail(), 'name' => fake()->name(), 'delivery_status' => 'pending'];
    }
}
