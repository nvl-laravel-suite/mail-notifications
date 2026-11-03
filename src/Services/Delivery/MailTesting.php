<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Services\MailTestingInterceptor;

/**
 * Applies configured recipient interception and non-delivery testing policy before provider submission.
 *
 * @phpstan-import-type ManagedMessageInput from EmailContent
 *
 * @internal
 */
class MailTesting
{
    public function __construct(private MailTestingInterceptor $interceptor) {}

    /**
     * Resolve the configured effective test recipient while retaining original-recipient evidence.
     *
     * @param  ManagedMessageInput  $input
     * @return array{original_recipients: array{to: list<array{email: string, name?: string|null}>, cc: list<array{email: string, name?: string|null}>, bcc: list<array{email: string, name?: string|null}>}, effective_recipient: array{email: string, name: string|null}}|null
     */
    public function interception(array $input): ?array
    {
        $recipient = $this->recipient();
        if ($recipient === null) {
            return null;
        }

        return [
            'original_recipients' => ['to' => $input['to'], 'cc' => $input['cc'] ?? [], 'bcc' => $input['bcc'] ?? []],
            'effective_recipient' => $recipient,
        ];
    }

    /**
     * Explain whether non-delivery testing policy prevents a real provider submission.
     */
    public function blockingReason(EmailMessage $message): ?string
    {
        try {
            if ($this->recipient() === null) {
                return null;
            }
        } catch (ValidationException) {
            return 'testing_interception_invalid';
        }

        $effective = $message->testing_interception['effective_recipient'] ?? null;
        if (! is_array($effective) || ! $this->validRecipient($effective) || $message->recipients->count() !== 1) {
            return 'testing_interception_required';
        }
        $recipient = $message->recipients->first();
        if ($recipient === null || $recipient->type !== 'to' || $recipient->email !== $effective['email'] || $recipient->name !== ($effective['name'] ?? null)) {
            return 'testing_interception_required';
        }

        return null;
    }

    /** @return array{email: string, name: string|null}|null */
    private function recipient(): ?array
    {
        $settings = $this->interceptor->settings();
        if (! ($settings['enabled'] ?? false)) {
            return null;
        }

        $recipient = ['email' => $settings['to_address'] ?? null, 'name' => $settings['to_name'] ?? null];
        if (! is_string($recipient['email']) || ($recipient['name'] !== null && ! is_string($recipient['name'])) || ! $this->validRecipient($recipient)) {
            throw ValidationException::withMessages(['mail_testing' => 'Enabled mail testing requires a valid inbox address and recipient name.']);
        }
        if ($settings['respect_environment'] ?? true) {
            $environments = $settings['environments'] ?? [];
            $validator = Validator::make(['environments' => $environments], [
                'environments' => ['required', 'array', 'list', 'min:1'],
                'environments.*' => ['required', 'string', 'max:100'],
            ]);
            if (! is_array($environments) || $validator->fails() || ! in_array(app()->environment(), $environments, true)) {
                throw ValidationException::withMessages(['mail_testing' => 'Enabled mail testing requires an explicit allowed current environment.']);
            }
        }

        return ['email' => mb_strtolower($recipient['email']), 'name' => $recipient['name']];
    }

    /** @param array<string, mixed> $recipient */
    private function validRecipient(array $recipient): bool
    {
        return Validator::make($recipient, [
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'],
            'name' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ])->passes();
    }
}
