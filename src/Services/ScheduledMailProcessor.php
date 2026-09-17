<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SentMessage;
use Nvl\MailNotifications\Exceptions\MailDeliveryCancelled;
use Nvl\MailNotifications\Laravel\ScheduledMailFactory;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;
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
        private MailTenantEnvelope $tenantEnvelope,
        private TenantDeliveryProfileResolver $deliveryProfiles,
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
        $this->tenantEnvelope->assertScheduled($message);
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
            $sentMessage = $mailable->send(new ScheduledMailFactory(
                $this->mail,
                $data->recipients,
                $this->deliveryProfiles->resolve(),
            ));
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
     * Seed the Mailable with persisted recipients before its preparation hooks.
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

    }
}
