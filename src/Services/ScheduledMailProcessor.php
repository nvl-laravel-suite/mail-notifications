<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SentMessage;
use Nvl\MailNotifications\Exceptions\MailDeliveryCancelled;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Rebuilds and sends claimed messages outside database transactions.
 */
final readonly class ScheduledMailProcessor
{
    /**
     * Create the scheduled-mail delivery processor.
     */
    public function __construct(
        private ScheduledMailConfiguration $configuration,
        private ScheduledMessageFactoryRegistry $factories,
        private ScheduledMailClaimer $claimer,
        private ScheduledMailFinalizer $finalizer,
        private MailFactory $mail,
        private ScheduledMailInputGuard $input,
    ) {}

    /**
     * Process a bounded batch and return the number of claims handled.
     */
    public function process(?int $limit = null): int
    {
        if (! $this->configuration->enabled()) {
            return 0;
        }

        $batchSize = $this->configuration->batchSize($limit);
        $handledMessageIds = [];
        $processed = 0;

        while ($processed < $batchSize) {
            $message = $this->claimer->claim(
                limit: 1,
                excludedMessageIds: $handledMessageIds,
            )[0] ?? null;

            if (! $message instanceof ScheduledMailMessage) {
                break;
            }

            $handledMessageIds[] = $message->id;
            $this->deliver($message);
            $processed++;
        }

        return $processed;
    }

    /**
     * Deliver one claim after its claiming transaction has committed.
     */
    private function deliver(ScheduledMailMessage $message): void
    {
        $claimToken = $message->claim_token;

        if (! is_string($claimToken) || $claimToken === '') {
            return;
        }

        try {
            $data = ScheduledMessageData::fromModel($message);
            $this->input->assertPayload($data->payload);
            $this->input->assertRecipients($data->recipients);
            $factory = $this->factories->resolve(
                alias: $message->factory_alias,
                version: $message->payload_version,
            );
            $factory->validate($data->payloadVersion, $data->payload);
            $mailable = $factory->make($data);
            $this->enforcePersistedRecipients(
                mailable: $mailable,
                recipients: $data->recipients,
            );
        } catch (Throwable $exception) {
            $this->finalizer->markFailure(
                messageId: $message->id,
                claimToken: $claimToken,
                exception: $exception,
                terminal: true,
            );

            return;
        }

        try {
            $sentMessage = $mailable->send($this->mail);
        } catch (Throwable $exception) {
            $this->finalizer->markFailure(
                messageId: $message->id,
                claimToken: $claimToken,
                exception: $exception,
            );

            return;
        }

        if (! $sentMessage instanceof SentMessage) {
            $this->finalizer->markFailure(
                messageId: $message->id,
                claimToken: $claimToken,
                exception: new MailDeliveryCancelled(
                    'Scheduled mail delivery was cancelled before transport acceptance.',
                ),
                terminal: true,
            );

            return;
        }

        $this->finalizer->markSent($message->id, $claimToken);
    }

    /**
     * Replace every factory-defined recipient at Laravel's final message boundary.
     */
    private function enforcePersistedRecipients(
        Mailable $mailable,
        ScheduledRecipients $recipients,
    ): void {
        $mailable->to = [];
        $mailable->cc = [];
        $mailable->bcc = [];
        $mailable->to($recipients->toPayload());
        $mailable->cc($recipients->ccPayload());
        $mailable->bcc($recipients->bccPayload());

        $to = $this->addresses($recipients->to);
        $cc = $this->addresses($recipients->cc);
        $bcc = $this->addresses($recipients->bcc);

        $mailable->withSymfonyMessage(
            static function (Email $message) use ($to, $cc, $bcc): void {
                $message->to(...$to);

                if ($cc === []) {
                    $message->getHeaders()->remove('Cc');
                } else {
                    $message->cc(...$cc);
                }

                if ($bcc === []) {
                    $message->getHeaders()->remove('Bcc');
                } else {
                    $message->bcc(...$bcc);
                }
            },
        );
    }

    /**
     * Convert normalized scheduled recipients into Symfony addresses.
     *
     * @param  list<Recipient>  $recipients
     * @return list<Address>
     */
    private function addresses(array $recipients): array
    {
        return array_map(
            static fn (Recipient $recipient): Address => new Address(
                address: $recipient->email,
                name: $recipient->name ?? '',
            ),
            $recipients,
        );
    }
}
