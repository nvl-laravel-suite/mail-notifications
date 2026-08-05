<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Laravel\Transport;

use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Nvl\MailNotifications\Exceptions\MailDeliveryCancelled;
use Nvl\MailNotifications\Services\TrackingRuntime;
use Nvl\MailNotifications\Support\TrackingHeaders;
use Nvl\MailNotifications\ValueObjects\TransportResult;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Tracks the final effective message at the transport boundary.
 */
final readonly class TrackingTransport implements TransportInterface
{
    /**
     * Create a one-delivery transport decorator.
     */
    public function __construct(
        private TransportInterface $transport,
        private TrackingRuntime $runtime,
        private string $correlationId,
        private string $mailer,
    ) {}

    /**
     * Persist immediately before transport and synchronize its final outcome.
     */
    public function send(
        RawMessage $message,
        ?Envelope $envelope = null,
    ): ?SentMessage {
        if (! $message instanceof Email
            || ! $this->runtime->isAssociated(
                $message,
                $this->correlationId,
            )) {
            return $this->transport->send($message, $envelope);
        }

        $headers = $message->getHeaders();
        $headers->remove(TrackingHeaders::CORRELATION);
        $headers->addTextHeader(
            TrackingHeaders::CORRELATION,
            $this->correlationId,
        );
        $this->runtime->beforeTransport($this->correlationId, $message);

        try {
            $sentMessage = $this->transport->send($message, $envelope);
        } catch (Throwable $exception) {
            $this->runtime->failed($this->correlationId, $exception);

            throw $exception;
        }

        if (! $sentMessage instanceof SentMessage) {
            $this->runtime->failed(
                $this->correlationId,
                new MailDeliveryCancelled(
                    'Tracked mail transport did not accept the message.',
                ),
            );

            return null;
        }

        $this->runtime->accepted(
            $this->correlationId,
            new TransportResult(
                mailer: $this->mailer,
                message: new LaravelSentMessage($sentMessage),
            ),
        );

        return $sentMessage;
    }

    /**
     * Return the decorated transport name.
     */
    public function __toString(): string
    {
        return (string) $this->transport;
    }
}
