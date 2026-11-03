<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery\Providers;

use Illuminate\Mail\MailManager;
use Nvl\MailNotifications\Contracts\EmailProvider;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Services\Delivery\MessageMimeBuilder;
use Nvl\MailNotifications\Services\Delivery\ProviderPayloadLimit;
use Nvl\MailNotifications\Services\Delivery\ProviderResult;
use RuntimeException;
use Throwable;

/**
 * Builds and submits MIME through the configured SMTP account without host rendering side effects.
 *
 * @internal
 */
class SmtpProvider implements EmailProvider
{
    public function __construct(private MailManager $mailManager, private MessageMimeBuilder $mime, private ProviderPayloadLimit $limits) {}

    /**
     * Reject unsupported or oversized persisted content before this attempt reaches provider transport.
     */
    public function validatePayload(EmailMessage $message, EmailAttempt $attempt): void
    {
        $this->mime->validate($this->mime->build($message, $attempt), $this->limits, 'smtp');
    }

    /**
     * Submit the persisted envelope and bytes for this attempt; distinguish acceptance, rejection and uncertainty.
     */
    public function send(EmailMessage $message, EmailAttempt $attempt): ProviderResult
    {
        try {
            $email = $this->mime->build($message, $attempt);
            $this->mime->validate($email, $this->limits, 'smtp');
            $transport = $this->mailManager->build($this->configuration($attempt))->getSymfonyTransport();
        } catch (Throwable) {
            return new ProviderResult('failed', errorCode: 'message_preparation_failed', errorMessage: 'The message could not be prepared for SMTP delivery.');
        }

        try {
            $sent = $transport->send($email);
        } catch (Throwable) {
            return new ProviderResult('unknown', errorCode: 'smtp_error', errorMessage: 'SMTP acceptance could not be confirmed.');
        }

        if ($sent === null) {
            return new ProviderResult('unknown', errorCode: 'unconfirmed_acceptance', errorMessage: 'SMTP acceptance could not be confirmed.');
        }

        return new ProviderResult('submitted', $sent->getMessageId());
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(EmailAttempt $attempt): array
    {
        $credentials = ($attempt->providerAccount ?? throw new RuntimeException('The provider account is unavailable.'))->credentials;
        if (! isset($credentials['host']) || trim($credentials['host']) === '') {
            throw new RuntimeException('SMTP host is required.');
        }
        $encryption = $credentials['encryption'] ?? null;
        $port = (int) ($credentials['port'] ?? 587);

        return [
            'transport' => 'smtp',
            'host' => $credentials['host'],
            'port' => $port,
            'username' => $credentials['username'] ?? null,
            'password' => $credentials['password'] ?? null,
            'scheme' => $credentials['scheme'] ?? ($encryption === 'ssl' || $port === 465 ? 'smtps' : 'smtp'),
            'require_tls' => $encryption === 'tls',
            'auto_tls' => $encryption !== 'none',
            'timeout' => 20,
        ];
    }
}
