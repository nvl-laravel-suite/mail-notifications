<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\EmailMessageReference;

/**
 * Creates isolated Email Message Reference fixtures on the configured mail storage.
 *
 * @extends Factory<EmailMessageReference>
 */
class EmailMessageReferenceFactory extends Factory
{
    protected $model = EmailMessageReference::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<EmailMessageReference>, mixed>
     */
    public function definition(): array
    {
        return [
            'email_message_id' => EmailMessage::factory(),
            'client_id' => fn (array $attributes): string => EmailMessage::query()->whereKey($attributes['email_message_id'])->firstOrFail()->client_id,
            'type' => 'order',
            'reference_id' => fake()->uuid(),
        ];
    }
}
