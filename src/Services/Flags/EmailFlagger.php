<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Flags;

use Nvl\MailNotifications\Enums\EmailFlagType;
use Nvl\MailNotifications\Models\EmailFlagRule;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\EmailMessageFlag;
use Nvl\MailNotifications\Models\EmailRecipient;

/**
 * Snapshots matching client rules onto a message without creating cross-client tags.
 *
 * @internal
 */
class EmailFlagger
{
    /**
     * Snapshot matching client rules on this accepted message; retain evidence independently of later rule edits.
     */
    public function apply(EmailMessage $message): void
    {
        $addresses = $message->recipients()->get()->map(fn (EmailRecipient $recipient): string => mb_strtolower(trim($recipient->email)))->all();
        $domains = array_map(fn (string $email): string => mb_substr($email, mb_strrpos($email, '@') + 1), $addresses);
        $rules = EmailFlagRule::query()->where('client_id', $message->client_id)->where('enabled', true)->get();

        foreach ($rules as $rule) {
            $value = trim($rule->value);
            if ($value === '') {
                continue;
            }
            $matches = match ($rule->type) {
                EmailFlagType::User => in_array(mb_strtolower($value), $addresses, true),
                EmailFlagType::Domain => in_array(mb_strtolower($value), $domains, true),
                EmailFlagType::Subject => mb_stripos($message->subject, $value) !== false,
            };
            if ($matches) {
                EmailMessageFlag::query()->firstOrCreate(
                    ['email_message_id' => $message->id, 'email_flag_rule_id' => $rule->id],
                    ['client_id' => $message->client_id, 'label' => $rule->label, 'color' => $rule->color, 'type' => $rule->type, 'value' => $rule->value],
                );
            }
        }
    }
}
