<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Models\EmailEvent;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\EmailRecipient;

/**
 * Creates isolated Email Event fixtures on the configured mail storage.
 *
 * @extends Factory<EmailEvent>
 */
class EmailEventFactory extends Factory
{
    protected $model = EmailEvent::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<EmailEvent>, mixed>
     */
    public function definition(): array
    {
        return [
            'email_recipient_id' => EmailRecipient::factory(),
            'email_message_id' => fn (array $attributes): string => EmailRecipient::query()->whereKey($attributes['email_recipient_id'])->firstOrFail()->email_message_id,
            'provider_account_id' => fn (array $attributes): string => EmailMessage::query()->whereKey($attributes['email_message_id'])->firstOrFail()->provider_account_id,
            'provider_event_id' => (string) Str::uuid(), 'type' => 'delivered', 'occurred_at' => now(), 'metadata' => [],
        ];
    }
}
