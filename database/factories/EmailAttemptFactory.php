<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailMessage;

/**
 * Creates isolated Email Attempt fixtures on the configured mail storage.
 *
 * @extends Factory<EmailAttempt>
 */
class EmailAttemptFactory extends Factory
{
    protected $model = EmailAttempt::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<EmailAttempt>, mixed>
     */
    public function definition(): array
    {
        return ['email_message_id' => EmailMessage::factory(), 'provider_account_id' => fn (array $attributes): string => EmailMessage::query()->whereKey($attributes['email_message_id'])->firstOrFail()->provider_account_id, 'number' => 1, 'status' => 'pending', 'retryable' => false];
    }
}
