<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;

/**
 * Wraps a Laravel mailer behind the public mailer contract.
 */
final readonly class DecoratedMailer implements Mailer
{
    /**
     * Create the mailer decorator.
     */
    public function __construct(
        private Mailer $mailer,
    ) {}

    /**
     * Begin a message addressed to the supplied recipients.
     */
    public function to(mixed $users): PendingMail
    {
        return $this->mailer->to($users);
    }

    /**
     * Begin a message copied to the supplied recipients.
     */
    public function cc(mixed $users): PendingMail
    {
        return $this->mailer->cc($users);
    }

    /**
     * Begin a message blind-copied to the supplied recipients.
     */
    public function bcc(mixed $users): PendingMail
    {
        return $this->mailer->bcc($users);
    }

    /**
     * Send a raw text message.
     */
    public function raw(mixed $text, mixed $callback): ?SentMessage
    {
        return $this->mailer->raw($text, $callback);
    }

    /**
     * Send a view or Mailable.
     *
     * @param  Mailable|string|array<array-key, mixed>  $view
     * @param  array<string, mixed>  $data
     * @param  \Closure|string|null  $callback
     */
    public function send(
        mixed $view,
        array $data = [],
        mixed $callback = null,
    ): ?SentMessage {
        return $this->mailer->send($view, $data, $callback);
    }

    /**
     * Send a view or Mailable synchronously.
     *
     * @param  Mailable|string|array<array-key, mixed>  $mailable
     * @param  array<string, mixed>  $data
     * @param  \Closure|string|null  $callback
     */
    public function sendNow(
        mixed $mailable,
        array $data = [],
        mixed $callback = null,
    ): ?SentMessage {
        return $this->mailer->sendNow($mailable, $data, $callback);
    }
}
