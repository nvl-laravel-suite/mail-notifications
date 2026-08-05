<?php

declare(strict_types=1);

use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Tests\Fixtures\SmtpTestProcess;
use Nvl\MailNotifications\Tests\Fixtures\TrackedMail;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/**
 * Configure one Laravel SMTP mailer for the isolated server.
 */
function configureNvlSmtpTestMailer(string $mailer, int $port): void
{
    config()->set("mail.mailers.{$mailer}", [
        'transport' => 'smtp',
        'scheme' => 'smtp',
        'host' => '127.0.0.1',
        'port' => $port,
        'username' => null,
        'password' => null,
        'timeout' => 5,
        'local_domain' => 'mail-package.test',
    ]);
    app(MailManager::class)->forgetMailers();
}

it('tracks acceptance through a real SMTP socket transport', function () {
    $smtp = SmtpTestProcess::start(acceptMessage: true);

    try {
        configureNvlSmtpTestMailer('smtp-live', $smtp->port);

        Mail::mailer('smtp-live')
            ->to('smtp-recipient@example.test')
            ->send(new TrackedMail(category: 'test.smtp-live'));

        app(MailManager::class)->forgetMailers();
        $exitCode = $smtp->wait();
        $capturedMessage = file_get_contents($smtp->capturePath);
        $notification = MailNotification::query()->sole();

        expect($exitCode)->toBe(0)
            ->and($capturedMessage)->toBeString()
            ->toContain('Subject: Tracked message')
            ->toContain('smtp-recipient@example.test')
            ->and($notification)
            ->status->toBe(MailDeliveryStatus::Accepted)
            ->mailer->toBe('smtp-live')
            ->provider->toBe('smtp-live')
            ->provider_message_id->not->toBeNull()
            ->primary_recipient_email->toBe('smtp-recipient@example.test');
    } finally {
        app(MailManager::class)->forgetMailers();
        $smtp->stop();

        if (is_file($smtp->capturePath)) {
            unlink($smtp->capturePath);
        }
    }
});

it('records a real SMTP rejection without replacing the transport exception', function () {
    $smtp = SmtpTestProcess::start(acceptMessage: false);

    try {
        configureNvlSmtpTestMailer('smtp-rejecting', $smtp->port);

        expect(fn () => Mail::mailer('smtp-rejecting')
            ->to('smtp-recipient@example.test')
            ->send(new TrackedMail(category: 'test.smtp-rejected')))
            ->toThrow(UnexpectedResponseException::class);

        app(MailManager::class)->forgetMailers();
        $exitCode = $smtp->wait();
        $notification = MailNotification::query()->sole();

        expect($exitCode)->toBe(2)
            ->and($notification)
            ->status->toBe(MailDeliveryStatus::Failed)
            ->mailer->toBe('smtp-rejecting')
            ->provider->toBeNull()
            ->provider_message_id->toBeNull()
            ->and($notification->metadata)->toHaveKey('failure.exception');
    } finally {
        app(MailManager::class)->forgetMailers();
        $smtp->stop();

        if (is_file($smtp->capturePath)) {
            unlink($smtp->capturePath);
        }
    }
});
