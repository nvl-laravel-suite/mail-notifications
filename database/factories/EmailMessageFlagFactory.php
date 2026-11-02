<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Enums\EmailFlagType;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\EmailMessageFlag;

/**
 * Creates isolated Email Message Flag fixtures on the configured mail storage.
 *
 * @extends Factory<EmailMessageFlag>
 */
class EmailMessageFlagFactory extends Factory
{
    protected $model = EmailMessageFlag::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<EmailMessageFlag>, mixed>
     */
    public function definition(): array
    {
        return [
            'email_message_id' => EmailMessage::factory(),
            'client_id' => fn (array $attributes): string => EmailMessage::query()->whereKey($attributes['email_message_id'])->firstOrFail()->client_id,
            'email_flag_rule_id' => null, 'label' => 'Billing', 'color' => '#2563eb', 'type' => EmailFlagType::Subject, 'value' => 'invoice',
        ];
    }
}
