<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
 * Submits the persisted envelope and attachments using the configured SendGrid account.
 */
class SendGridProvider implements EmailProvider
{
    public function __construct(private ProviderRetryDelay $retryDelay, private ProviderPayloadLimit $limits, private MailMediaStorage $media) {}

    /**
     * Reject unsupported or oversized persisted content before this attempt reaches provider transport.
     */
    public function validatePayload(EmailMessage $message, EmailAttempt $attempt): void
    {
        $this->limits->json($this->payload($message, $attempt, false), $message->messageAssets, 'sendgrid');
    }

    /**
     * Submit the persisted envelope and bytes for this attempt; distinguish acceptance, rejection and uncertainty.
     */
    public function send(EmailMessage $message, EmailAttempt $attempt): ProviderResult
    {
        $credentials = ($attempt->providerAccount ?? throw new RuntimeException('The provider account is unavailable.'))->credentials;
        if (empty($credentials['api_key']) || ! in_array($credentials['region'] ?? 'us', ['us', 'eu'], true)) {
            return new ProviderResult('failed', errorCode: 'invalid_credentials', errorMessage: 'A SendGrid API key and valid region are required.');
        }
        try {
            $payload = $this->payload($message, $attempt);
            $this->limits->check(strlen(json_encode($payload, JSON_THROW_ON_ERROR)), 'sendgrid');
        } catch (Throwable) {
            return new ProviderResult('failed', errorCode: 'message_preparation_failed', errorMessage: 'The message could not be prepared for delivery.');
        }
        $base = ($credentials['region'] ?? 'us') === 'eu' ? 'https://api.eu.sendgrid.com' : 'https://api.sendgrid.com';
        try {
            $response = Http::withToken($credentials['api_key'])->acceptJson()->asJson()
                ->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])
                ->post($base.'/v3/mail/send', $payload);
        } catch (ConnectionException) {
            return new ProviderResult('unknown', errorCode: 'connection_error', errorMessage: 'SendGrid acceptance could not be confirmed.');
        }
        $safe = ['status' => $response->status()];
        if ($response->clientError()) {
            return new ProviderResult('failed', errorCode: 'http_'.$response->status(), errorMessage: 'SendGrid rejected the message (HTTP '.$response->status().').', retryable: $response->status() === 429, response: $safe, retryAfter: $this->retryDelay->seconds($response));
        }
        $id = trim($response->header('X-Message-ID'));
        if ($response->status() === 202 && $id !== '' && strlen($id) <= 255 && ! preg_match('/[^\x21-\x7e]/', $id)) {
            return new ProviderResult('submitted', $id, response: $safe);
        }

        return new ProviderResult('unknown', errorCode: 'unconfirmed_acceptance', errorMessage: 'SendGrid acceptance could not be confirmed.', response: $safe);
    }

    /** @return array<string, mixed> */
    private function payload(EmailMessage $message, EmailAttempt $attempt, bool $includeContent = true): array
    {
        $envelope = ['custom_args' => ['attempt_id' => $attempt->id]];
        foreach (['to', 'cc', 'bcc'] as $type) {
            $recipients = $message->recipients->where('type', $type)->map(fn (EmailRecipient $recipient): array => $this->address($recipient->email, $recipient->name))->values()->all();
            if ($recipients !== []) {
                $envelope[$type] = $recipients;
            }
        }
        $payload = ['from' => $this->address($message->from_email, $message->from_name), 'subject' => $message->subject, 'personalizations' => [$envelope], 'content' => []];
        foreach (['text' => 'text/plain', 'html' => 'text/html'] as $field => $mime) {
            if ($message->{$field} !== null && $message->{$field} !== '') {
                $payload['content'][] = ['type' => $mime, 'value' => $message->{$field}];
            }
        }
        if ($message->reply_to_email !== null) {
            $payload['reply_to'] = $this->address($message->reply_to_email, $message->reply_to_name);
        }
        if ($message->tags) {
            $payload['categories'] = array_slice($message->tags, 0, 4);
        }
        if ($message->messageAssets->isNotEmpty()) {
            $payload['attachments'] = $message->messageAssets->map(function (MessageAsset $asset) use ($includeContent): array {
                $bytes = $includeContent ? $this->media->bytes($asset) : '';
                if ($includeContent && ! hash_equals($asset->checksum, hash('sha256', $bytes))) {
                    throw new RuntimeException('The attachment is unavailable or changed.');
                }
                $attachment = ['content' => base64_encode($bytes), 'type' => $asset->mime_type, 'filename' => $asset->name, 'disposition' => $asset->disposition];
                if ($asset->disposition === 'inline' && $asset->content_id !== null) {
                    $attachment['content_id'] = $asset->content_id;
                }

                return $attachment;
            })->all();
        }

        return $payload;
    }

    /** @return array{email: string, name?: string} */
    private function address(string $email, ?string $name): array
    {
        return $name ? ['email' => $email, 'name' => $name] : ['email' => $email];
    }
}
