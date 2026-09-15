<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Laravel;

use Closure;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use InvalidArgumentException;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;
use Symfony\Component\Mime\Address;

/**
 * Applies persisted recipients after Mailable callbacks and before host interception.
 *
 * @internal
 */
final readonly class ScheduledRecipientMailer implements Mailer
{
    /**
     * Wrap one host mailer without modifying its shared configuration.
     */
    public function __construct(
        public Mailer $mailer,
        private ScheduledRecipients $recipients,
    ) {}

    /**
     * Begin a pending message through this delivery boundary.
     */
    public function to(mixed $users): PendingMail
    {
        return (new PendingMail($this))->to($users);
    }

    /**
     * Begin a pending message with copy recipients.
     */
    public function cc(mixed $users): PendingMail
    {
        return (new PendingMail($this))->cc($users);
    }

    /**
     * Begin a pending message with hidden recipients.
     */
    public function bcc(mixed $users): PendingMail
    {
        return (new PendingMail($this))->bcc($users);
    }

    /**
     * Send raw content through the host mailer with the persisted envelope.
     */
    public function raw(mixed $text, mixed $callback): ?SentMessage
    {
        return $this->mailer->raw($text, $this->finalizeRecipients($callback));
    }

    /**
     * Send prepared content after completing all Mailable callbacks.
     *
     * @param  Mailable|string|array<string, mixed>  $view
     * @param  array<string, mixed>  $data
     */
    public function send(mixed $view, array $data = [], mixed $callback = null): ?SentMessage
    {
        if ($view instanceof Mailable) {
            return $view->send($this);
        }

        return $this->mailer->send($view, $data, $this->finalizeRecipients($callback));
    }

    /**
     * Send synchronously through the same recipient boundary.
     *
     * @param  Mailable|string|array<string, mixed>  $mailable
     * @param  array<string, mixed>  $data
     */
    public function sendNow(mixed $mailable, array $data = [], mixed $callback = null): ?SentMessage
    {
        return $this->send($mailable, $data, $callback);
    }

    /**
     * Append recipient enforcement to the complete Mailable build callback.
     *
     * @return Closure(Message): void
     */
    private function finalizeRecipients(mixed $callback): Closure
    {
        if ($callback !== null && ! is_callable($callback)) {
            throw new InvalidArgumentException('Scheduled mail callbacks must be callable.');
        }

        return function (Message $message) use ($callback): void {
            if ($callback !== null) {
                $callback($message);
            }

            $email = $message->getSymfonyMessage();
            $email->to(...$this->addresses($this->recipients->to));
            $email->getHeaders()->remove('Cc');
            $email->getHeaders()->remove('Bcc');

            if ($this->recipients->cc !== []) {
                $email->cc(...$this->addresses($this->recipients->cc));
            }

            if ($this->recipients->bcc !== []) {
                $email->bcc(...$this->addresses($this->recipients->bcc));
            }
        };
    }

    /**
     * Convert normalized scheduled recipients to Symfony addresses.
     *
     * @param  list<Recipient>  $recipients
     * @return list<Address>
     */
    private function addresses(array $recipients): array
    {
        return array_map(
            static fn (Recipient $recipient): Address => new Address($recipient->email, $recipient->name ?? ''),
            $recipients,
        );
    }
}
