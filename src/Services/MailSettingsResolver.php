<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use InvalidArgumentException;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\SenderIdentity;

/**
 * Combines owner defaults and client overrides without transferring provider ownership.
 */
class MailSettingsResolver
{
    /**
     * Resolve effective owner/client delivery settings without allowing another owner's account or sender.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{provider_account_id: ?string, sender_identity_id: ?string, from_email: ?string, from_name: ?string, reply_to_email: ?string, reply_to_name: ?string}
     */
    public function resolve(Client $client, array $overrides = []): array
    {
        $settings = array_replace($client->mailSettings?->getAttributes() ?? [], $overrides);
        $owner = $client->owner->mailSettings;
        $senderId = $this->setting($settings, 'sender_identity_id');
        $sender = $senderId === null ? null : ($senderId === $client->mailSettings?->sender_identity_id
            ? $client->mailSettings->senderIdentity
            : SenderIdentity::query()->where('owner_id', $client->owner_id)->whereKey($senderId)->first());

        return [
            'provider_account_id' => $this->setting($settings, 'provider_account_id') ?? $owner?->default_provider_account_id,
            'sender_identity_id' => $senderId,
            'from_email' => $this->setting($settings, 'from_email') ?? $sender->email ?? $owner?->from_email,
            'from_name' => $this->setting($settings, 'from_name') ?? $sender->name ?? $owner?->from_name,
            'reply_to_email' => $this->setting($settings, 'reply_to_email') ?? $sender->reply_to_email ?? $owner?->reply_to_email,
            'reply_to_name' => $this->setting($settings, 'reply_to_name') ?? $sender->reply_to_name ?? $owner?->reply_to_name,
        ];
    }

    /** @param array<string, mixed> $settings */
    private function setting(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException('Mail setting ['.$key.'] must be a string or null.');
        }

        return $value;
    }
}
