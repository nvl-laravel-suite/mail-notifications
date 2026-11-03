<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\EmailProvider;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Services\Delivery\Providers\MailerSendProvider;
use Nvl\MailNotifications\Services\Delivery\Providers\MailgunProvider;
use Nvl\MailNotifications\Services\Delivery\Providers\SendGridProvider;
use Nvl\MailNotifications\Services\Delivery\Providers\SmtpProvider;

/**
 * Resolves the reviewed SMTP, MailerSend, SendGrid and Mailgun submission adapters.
 *
 * @internal
 */
class ProviderManager
{
    public function __construct(
        private MailerSendProvider $mailerSend,
        private SmtpProvider $smtp,
        private SendGridProvider $sendGrid,
        private MailgunProvider $mailgun,
    ) {}

    /**
     * Resolve only supported submission drivers from the persisted provider account.
     */
    public function providerFor(ProviderAccount $account): EmailProvider
    {
        return match ($account->provider) {
            'mailersend' => $this->mailerSend,
            'smtp' => $this->smtp,
            'sendgrid' => $this->sendGrid,
            'mailgun' => $this->mailgun,
            default => throw new InvalidArgumentException('Unsupported email provider.'),
        };
    }
}
