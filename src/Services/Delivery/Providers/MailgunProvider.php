<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Nvl\MailNotifications\Contracts\EmailProvider;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Services\Delivery\MessageMimeBuilder;
use Nvl\MailNotifications\Services\Delivery\ProviderPayloadLimit;
use Nvl\MailNotifications\Services\Delivery\ProviderResult;
use Nvl\MailNotifications\Services\Delivery\ProviderRetryDelay;
use Throwable;

/**
 * Submits the persisted envelope and attachments using the configured Mailgun account.
 *
 * @internal
 */
class MailgunProvider implements EmailProvider
{
    public function __construct(private ProviderRetryDelay $retryDelay, private MessageMimeBuilder $mime, private ProviderPayloadLimit $limits) {}

    /**
     * Reject unsupported or oversized persisted content before this attempt reaches provider transport.
     */
    public function validatePayload(EmailMessage $message, EmailAttempt $attempt): void
    {
        $this->mime->validate($this->mime->build($message, $attempt, false), $this->limits, 'mailgun');
    }

    /**
     * Submit the persisted envelope and bytes for this attempt; distinguish acceptance, rejection and uncertainty.
     */
    public function send(EmailMessage $message, EmailAttempt $attempt): ProviderResult
    {
        $credentials = ($attempt->providerAccount ?? throw new \RuntimeException('The provider account is unavailable.'))->credentials;
        $domain = $credentials['domain'] ?? '';
        if (empty($credentials['api_key']) || ! preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/i', $domain) || ! in_array($credentials['region'] ?? 'us', ['us', 'eu'], true)) {
            return new ProviderResult('failed', errorCode: 'invalid_credentials', errorMessage: 'A Mailgun API key, sending domain and valid region are required.');
        }
        try {
            $mime = $this->mime->stream($this->mime->build($message, $attempt, false), $this->limits, 'mailgun');
        } catch (Throwable) {
            return new ProviderResult('failed', errorCode: 'message_preparation_failed', errorMessage: 'The message could not be prepared for delivery.');
        }
        $base = ($credentials['region'] ?? 'us') === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
        $parts = [
            ['name' => 'message', 'contents' => $mime, 'filename' => 'message.eml', 'headers' => ['Content-Type' => 'message/rfc822']],
            ['name' => 'to', 'contents' => $message->recipients->pluck('email')->implode(',')],
            ['name' => 'v:attempt_id', 'contents' => $attempt->id],
        ];
        foreach (array_slice($message->tags ?? [], 0, 3) as $tag) {
            $parts[] = ['name' => 'o:tag', 'contents' => $tag];
        }
        try {
            $response = Http::withBasicAuth('api', $credentials['api_key'])->acceptJson()->asMultipart()
                ->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])
                ->post($base.'/v3/'.rawurlencode($domain).'/messages.mime', $parts);
        } catch (ConnectionException) {
            return new ProviderResult('unknown', errorCode: 'connection_error', errorMessage: 'Mailgun acceptance could not be confirmed.');
        } finally {
            if (is_resource($mime)) {
                fclose($mime);
            }
        }
        $safe = ['status' => $response->status()];
        if ($response->clientError()) {
            return new ProviderResult('failed', errorCode: 'http_'.$response->status(), errorMessage: 'Mailgun rejected the message (HTTP '.$response->status().').', retryable: $response->status() === 429, response: $safe, retryAfter: $this->retryDelay->seconds($response));
        }
        $id = $response->json('id');
        if ($response->status() === 200 && is_string($id) && trim($id, '<>') !== '' && strlen(trim($id, '<>')) <= 253 && ! preg_match('/[^\x21-\x7e]/', $id)) {
            return new ProviderResult('submitted', '<'.trim($id, '<>').'>', response: $safe);
        }

        return new ProviderResult('unknown', errorCode: 'unconfirmed_acceptance', errorMessage: 'Mailgun acceptance could not be confirmed.', response: $safe);
    }
}
