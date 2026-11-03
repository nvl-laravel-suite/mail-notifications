<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Contracts\EmailProvider;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\EmailRecipient;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Services\Delivery\MailMediaStorage;
use Nvl\MailNotifications\Services\Delivery\ProviderPayloadLimit;
use Nvl\MailNotifications\Services\Delivery\ProviderResult;
use Nvl\MailNotifications\Services\Delivery\ProviderRetryDelay;
use RuntimeException;
use Throwable;

/**
 * Submits the persisted envelope and attachments using the configured MailerSend account.
 *
 * @internal
 */
class MailerSendProvider implements EmailProvider
{
    public function __construct(private ProviderRetryDelay $retryDelay, private ProviderPayloadLimit $limits, private MailMediaStorage $media) {}

    /**
     * Reject unsupported or oversized persisted content before this attempt reaches provider transport.
     */
    public function validatePayload(EmailMessage $message, EmailAttempt $attempt): void
    {
        $this->validateContent($message);
        $this->limits->json($this->payload($message, $attempt, false), $message->messageAssets, 'mailersend');
    }

    /**
     * Submit the persisted envelope and bytes for this attempt; distinguish acceptance, rejection and uncertainty.
     */
    public function send(EmailMessage $message, EmailAttempt $attempt): ProviderResult
    {
        $apiKey = $attempt->providerAccount->credentials['api_key'] ?? null;
        if ($apiKey === null || trim($apiKey) === '') {
            return new ProviderResult('failed', errorCode: 'invalid_credentials', errorMessage: 'A MailerSend API key is required.');
        }

        try {
            $this->validateContent($message);
            $payload = $this->payload($message, $attempt);
            $this->limits->check(strlen(json_encode($payload, JSON_THROW_ON_ERROR)), 'mailersend');
        } catch (Throwable) {
            return new ProviderResult('failed', errorCode: 'message_preparation_failed', errorMessage: 'The message could not be prepared for delivery.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->post('https://api.mailersend.com/v1/email', $payload);
        } catch (ConnectionException) {
            return new ProviderResult('unknown', errorCode: 'connection_error', errorMessage: 'MailerSend acceptance could not be confirmed.');
        }

        $safeResponse = $this->safeResponse($response, $message);
        $suppressedEmails = $safeResponse['suppressed_emails'] ?? [];

        if (in_array('ALL_SUPPRESSED', $safeResponse['warnings'] ?? [], true)) {
            return new ProviderResult('failed', errorCode: 'all_suppressed', errorMessage: 'All recipients were suppressed by MailerSend.', suppressedEmails: $suppressedEmails, response: $safeResponse);
        }

        if ($response->clientError()) {
            return new ProviderResult(
                'failed',
                errorCode: 'http_'.$response->status(),
                errorMessage: 'MailerSend rejected the message (HTTP '.$response->status().').',
                retryable: $response->status() === 429,
                response: $safeResponse,
                retryAfter: $this->retryDelay->seconds($response, dailyQuota: true),
            );
        }

        $messageId = trim($response->header('x-message-id'));

        if ($response->status() === 202 && $messageId !== '' && strlen($messageId) <= 255 && preg_match('/^[a-zA-Z0-9_-]+$/', $messageId)) {
            return new ProviderResult('submitted', $messageId, suppressedEmails: $suppressedEmails, response: $safeResponse);
        }

        return new ProviderResult('unknown', errorCode: 'unconfirmed_acceptance', errorMessage: 'MailerSend acceptance could not be confirmed.', suppressedEmails: $suppressedEmails, response: $safeResponse);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EmailMessage $message, EmailAttempt $attempt, bool $includeContent = true): array
    {
        $payload = [
            'from' => $this->address($message->from_email, $message->from_name),
            'subject' => $message->subject,
        ];

        foreach (['html', 'text'] as $contentType) {
            if ($message->{$contentType} !== null) {
                $payload[$contentType] = $message->{$contentType};
            }
        }

        foreach (['to', 'cc', 'bcc'] as $recipientType) {
            $recipients = $message->recipients->where('type', $recipientType)
                ->map(fn (EmailRecipient $recipient): array => $this->address($recipient->email, $recipient->name))
                ->values()->all();

            if ($recipients !== []) {
                $payload[$recipientType] = $recipients;
            }
        }

        if ($message->reply_to_email !== null) {
            $payload['reply_to'] = $this->address($message->reply_to_email, $message->reply_to_name);
        }

        $payload['tags'] = array_merge(['attempt-'.$attempt->id], array_slice($message->tags ?? [], 0, 4));

        if ($message->messageAssets->isNotEmpty()) {
            $payload['attachments'] = $message->messageAssets->map(function (MessageAsset $asset) use ($includeContent): array {
                $content = $includeContent ? $this->media->bytes($asset) : '';

                if ($includeContent && ! hash_equals($asset->checksum, hash('sha256', $content))) {
                    throw new RuntimeException('Message asset no longer matches its snapshot.');
                }

                $attachment = [
                    'content' => base64_encode($content),
                    'filename' => $asset->name,
                    'disposition' => $asset->disposition,
                ];

                if ($asset->disposition === 'inline' && $asset->content_id !== null) {
                    $attachment['id'] = $asset->content_id;
                }

                return $attachment;
            })->all();
        }

        return $payload;
    }

    private function validateContent(EmailMessage $message): void
    {
        foreach (['html', 'text'] as $field) {
            if (strlen($message->{$field} ?? '') > 2000000) {
                throw ValidationException::withMessages([$field => 'MailerSend content must be at most 2 MB per field.']);
            }
        }
    }

    /**
     * @return array{email: string, name?: string}
     */
    private function address(string $email, ?string $name): array
    {
        return $name !== null && $name !== '' ? ['email' => $email, 'name' => $name] : ['email' => $email];
    }

    /**
     * @return array{status: int, warnings?: list<string>, suppressed_emails?: list<string>}
     */
    private function safeResponse(Response $response, EmailMessage $message): array
    {
        $safeResponse = ['status' => $response->status()];
        $warnings = $response->json('warnings');

        if (! is_array($warnings)) {
            return $safeResponse;
        }

        $recipientEmails = $message->recipients->map(fn ($recipient): string => $recipient->email)->map(fn (string $email): string => strtolower($email))->all();
        $warningTypes = [];
        $suppressedEmails = [];

        foreach (array_slice($warnings, 0, 10) as $warning) {
            if (! is_array($warning) || ! in_array($warning['type'] ?? null, ['SOME_SUPPRESSED', 'ALL_SUPPRESSED'], true)) {
                continue;
            }

            $warningTypes[] = $warning['type'];

            foreach (array_slice(is_array($warning['recipients'] ?? null) ? $warning['recipients'] : [], 0, 100) as $recipient) {
                $email = is_array($recipient) ? ($recipient['email'] ?? null) : null;

                if (is_string($email) && in_array(strtolower($email), $recipientEmails, true)) {
                    $suppressedEmails[] = strtolower($email);
                }
            }
        }

        if ($warningTypes !== []) {
            $safeResponse['warnings'] = array_values(array_unique($warningTypes));
            $safeResponse['suppressed_emails'] = array_values(array_unique($suppressedEmails));
        }

        return $safeResponse;
    }
}
