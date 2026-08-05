<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Laravel\Concerns;

use Closure;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address as MailableAddress;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Illuminate\Queue\ManuallyFailedException;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Contracts\MailTrackable;
use Nvl\MailNotifications\Contracts\TrackableMessage;
use Nvl\MailNotifications\Exceptions\MailDeliveryCancelled;
use Nvl\MailNotifications\Support\TrackingHeaders;
use Nvl\MailNotifications\Support\TrackingRuntimeBridge;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use ReflectionMethod;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Adds explicit, per-message tracking controls to a Laravel Mailable.
 *
 * @mixin Mailable
 */
trait TracksMailDelivery
{
    private bool $mailTrackingEnabled = true;

    private bool $mailTrackingDeliveryInProgress = false;

    private ?string $mailTrackingCorrelationId = null;

    private ?string $mailTrackingQueueReference = null;

    private ?TrackingContext $mailTrackingQueuedFailureContext = null;

    private ?MailTrackable $mailTrackingNotifiable = null;

    /**
     * @var array<string, mixed>
     */
    private array $mailTrackingMetadata = [];

    private ?Closure $mailTrackingHeaderCallback = null;

    /**
     * Opt this Mailable instance into tracking.
     */
    public function withMailTracking(): static
    {
        $this->mailTrackingEnabled = true;

        return $this;
    }

    /**
     * Opt this Mailable instance out of tracking without changing delivery.
     */
    public function withoutMailTracking(): static
    {
        $this->mailTrackingEnabled = false;

        return $this;
    }

    /**
     * Determine whether this Mailable instance currently opts into tracking.
     */
    public function hasMailTrackingEnabled(): bool
    {
        return $this->mailTrackingEnabled;
    }

    /**
     * Associate a host-owned notifiable with the next tracked delivery.
     */
    public function forNotifiable(MailTrackable $notifiable): static
    {
        $this->mailTrackingNotifiable = $notifiable;

        return $this;
    }

    /**
     * Merge safe host metadata into the next tracked delivery.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withTrackingMetadata(array $metadata): static
    {
        $this->mailTrackingMetadata = array_replace(
            $this->mailTrackingMetadata,
            $metadata,
        );

        return $this;
    }

    /**
     * Handle Laravel's terminal queued-Mailable failure callback.
     *
     * A host-defined failed() method must call recordMailTrackingFailure()
     * before or after its own application-specific failure behavior.
     */
    public function failed(?Throwable $exception): void
    {
        $this->recordMailTrackingFailure($exception);
    }

    /**
     * Persist one terminal queued-Mailable failure without replacing its exception.
     */
    public function recordMailTrackingFailure(?Throwable $exception): void
    {
        $exception ??= new ManuallyFailedException;
        $queueReference = $this->mailTrackingQueueReference;

        try {
            if (! $this->shouldStageMailTracking()) {
                return;
            }

            $queueReference ??= (string) Str::uuid();
            $this->mailTrackingQueueReference = $queueReference;

            TrackingRuntimeBridge::queuedFailure(
                context: $this->mailTrackingQueuedFailureContext
                    ?? $this->resolvedTrackingContext(),
                mailer: $this->mailer,
                queueReference: $queueReference,
                message: $this->mailTrackingFailureMessage(),
                exception: $exception,
            );
        } catch (Throwable $trackingException) {
            $queueReference ??= (string) Str::uuid();

            try {
                TrackingRuntimeBridge::queuedFailureSynchronizationFailed(
                    $queueReference,
                    $trackingException,
                );
            } catch (Throwable) {
                // The original queue exception must remain dominant.
            }
        } finally {
            $this->mailTrackingNotifiable = null;
            $this->mailTrackingMetadata = [];
            $this->mailTrackingQueuedFailureContext = null;
        }
    }

    /**
     * Queue the message while keeping its identity on the serialized copy only.
     */
    public function queue(QueueFactory $queue): mixed
    {
        try {
            return parent::queue($queue);
        } finally {
            $this->mailTrackingQueueReference = null;
            $this->mailTrackingQueuedFailureContext = null;
        }
    }

    /**
     * Queue the delayed message while keeping identity on the serialized copy only.
     */
    public function later($delay, QueueFactory $queue): mixed
    {
        try {
            return parent::later($delay, $queue);
        } finally {
            $this->mailTrackingQueueReference = null;
            $this->mailTrackingQueuedFailureContext = null;
        }
    }

    /**
     * Send the message while synchronizing local delivery failures.
     *
     * @param  MailFactory|MailerContract  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        $this->mailTrackingDeliveryInProgress = true;
        $resolvedMailer = null;
        $originalTransport = null;

        try {
            $resolvedMailer = $this->resolveMailer($mailer);

            if ($this->shouldStageMailTracking()) {
                if (! $resolvedMailer instanceof Mailer) {
                    TrackingRuntimeBridge::unsupportedMailer();
                } else {
                    $this->stageMailTracking();
                }
            }

            if ($this->mailTrackingCorrelationId !== null
                && $resolvedMailer instanceof Mailer) {
                $originalTransport = $resolvedMailer->getSymfonyTransport();
                $resolvedMailer->setSymfonyTransport(
                    TrackingRuntimeBridge::wrapTransport(
                        $this->mailTrackingCorrelationId,
                        $originalTransport,
                    ),
                );
            }

            $sentMessage = parent::send($resolvedMailer);

            if ($this->mailTrackingCorrelationId !== null && $sentMessage === null) {
                TrackingRuntimeBridge::cancelled(
                    $this->mailTrackingCorrelationId,
                    new MailDeliveryCancelled('Tracked mail delivery was cancelled before transport.'),
                );
            }

            return $sentMessage;
        } catch (Throwable $exception) {
            if ($this->mailTrackingCorrelationId !== null) {
                TrackingRuntimeBridge::cancelled(
                    $this->mailTrackingCorrelationId,
                    $exception,
                );
            }

            throw $exception;
        } finally {
            if ($resolvedMailer instanceof Mailer
                && $originalTransport instanceof TransportInterface) {
                $resolvedMailer->setSymfonyTransport($originalTransport);
            }

            $this->removeMailTrackingHeaderCallback();
            $this->mailTrackingCorrelationId = null;
            $this->mailTrackingNotifiable = null;
            $this->mailTrackingMetadata = [];
            $this->mailTrackingDeliveryInProgress = false;
        }
    }

    /**
     * Prepare an opted-in message with a non-sensitive correlation header.
     */
    protected function prepareMailableForDelivery(): void
    {
        parent::prepareMailableForDelivery();

        if (! $this->mailTrackingDeliveryInProgress
            || $this->mailTrackingCorrelationId === null) {
            $this->mailTrackingCorrelationId = null;

            return;
        }

        $this->registerMailTrackingHeaderCallback();
    }

    /**
     * Stage tracking before Laravel resolves and invokes the concrete transport.
     */
    private function shouldStageMailTracking(): bool
    {
        return $this instanceof TrackableMessage
            && $this->mailTrackingEnabled
            && TrackingRuntimeBridge::available()
            && TrackingRuntimeBridge::shouldTrack($this->mailer);
    }

    /**
     * Stage tracking after validating the concrete mailer capability.
     */
    private function stageMailTracking(): void
    {
        $context = $this->resolvedTrackingContext();

        if ($this->mailTrackingQueueReference !== null) {
            $this->mailTrackingQueuedFailureContext = $context;
        }

        $this->mailTrackingCorrelationId = TrackingRuntimeBridge::stage(
            $context,
            $this->mailer,
            $this->mailTrackingQueueReference,
        );
    }

    /**
     * Apply the concern's fluent host integration to the message context.
     */
    private function resolvedTrackingContext(): TrackingContext
    {
        $context = $this->trackingContext();

        if ($this->mailTrackingNotifiable instanceof MailTrackable) {
            $context = $context->forNotifiable(
                $this->mailTrackingNotifiable,
            );
        }

        if ($this->mailTrackingMetadata !== []) {
            $context = $context->withMetadata(
                $this->mailTrackingMetadata,
            );
        }

        return $context;
    }

    /**
     * Resolve the exact mailer instance Laravel should use for this delivery.
     */
    private function resolveMailer(
        MailFactory|MailerContract $mailer,
    ): MailerContract {
        if ($mailer instanceof MailerContract) {
            return $mailer;
        }

        return $mailer->mailer($this->mailer);
    }

    /**
     * Register one delivery-only Symfony correlation header callback.
     */
    private function registerMailTrackingHeaderCallback(): void
    {
        if ($this->mailTrackingHeaderCallback instanceof Closure) {
            return;
        }

        $this->mailTrackingHeaderCallback = function (Email $message): void {
            $headers = $message->getHeaders();
            $headers->remove(TrackingHeaders::CORRELATION);

            if ($this->mailTrackingCorrelationId !== null) {
                $headers->addTextHeader(
                    TrackingHeaders::CORRELATION,
                    $this->mailTrackingCorrelationId,
                );
                TrackingRuntimeBridge::associate(
                    $message,
                    $this->mailTrackingCorrelationId,
                );
            }
        };
        $this->withSymfonyMessage($this->mailTrackingHeaderCallback);
    }

    /**
     * Remove the delivery-only callback so the Mailable remains serializable.
     */
    private function removeMailTrackingHeaderCallback(): void
    {
        if (! $this->mailTrackingHeaderCallback instanceof Closure) {
            return;
        }

        $callback = $this->mailTrackingHeaderCallback;
        $this->callbacks = array_values(array_filter(
            $this->callbacks,
            static fn (mixed $registered): bool => $registered !== $callback,
        ));
        $this->mailTrackingHeaderCallback = null;
    }

    /**
     * Assign a non-sensitive queue identity before Laravel serializes the Mailable.
     */
    protected function newQueuedJob(): mixed
    {
        $this->mailTrackingQueueReference = $this instanceof TrackableMessage
            && $this->mailTrackingEnabled
                ? (string) Str::uuid()
                : null;

        return parent::newQueuedJob();
    }

    /**
     * Build a body-free message snapshot for a pre-send queue failure.
     */
    private function mailTrackingFailureMessage(): Email
    {
        $this->hydrateMailTrackingFailureEnvelope();
        $message = new Email;
        $from = $this->mailTrackingFailureAddresses($this->from);
        $to = $this->mailTrackingFailureAddresses($this->to);
        $cc = $this->mailTrackingFailureAddresses($this->cc);
        $bcc = $this->mailTrackingFailureAddresses($this->bcc);

        if ($from !== []) {
            $message->from(...$from);
        }

        if ($to !== []) {
            $message->to(...$to);
        }

        if ($cc !== []) {
            $message->cc(...$cc);
        }

        if ($bcc !== []) {
            $message->bcc(...$bcc);
        }

        $subject = $this->mailTrackingFailureString(
            get_object_vars($this)['subject'] ?? null,
        );
        $subject = $subject !== null && trim($subject) !== ''
            ? $subject
            : Str::title(Str::snake(class_basename($this), ' '));

        try {
            $message->subject($subject);
        } catch (Throwable) {
            // A malformed subject must not prevent a privacy-safe failure row.
        }

        return $message;
    }

    /**
     * Apply only declarative envelope data without rendering message content.
     */
    private function hydrateMailTrackingFailureEnvelope(): void
    {
        try {
            $envelope = (new ReflectionMethod($this, 'envelope'))
                ->invoke($this);

            if (! $envelope instanceof Envelope) {
                return;
            }

            if ($envelope->from !== null) {
                $this->hydrateMailTrackingFailureRecipient(
                    'from',
                    $envelope->from,
                );
            }

            foreach ($envelope->to as $address) {
                $this->hydrateMailTrackingFailureRecipient('to', $address);
            }

            foreach ($envelope->cc as $address) {
                $this->hydrateMailTrackingFailureRecipient('cc', $address);
            }

            foreach ($envelope->bcc as $address) {
                $this->hydrateMailTrackingFailureRecipient('bcc', $address);
            }

            if ($envelope->subject !== null
                && trim($envelope->subject) !== '') {
                $this->subject($envelope->subject);
            }
        } catch (Throwable) {
            // Existing fluent recipients still provide a minimal safe snapshot.
        }
    }

    /**
     * Apply one declarative envelope recipient to the minimal snapshot.
     */
    private function hydrateMailTrackingFailureRecipient(
        string $type,
        mixed $address,
    ): void {
        if ($address instanceof MailableAddress) {
            $email = $address->address;
            $name = $address->name;
        } elseif (is_string($address)) {
            $email = $address;
            $name = null;
        } else {
            return;
        }

        match ($type) {
            'from' => $this->from($email, $name),
            'to' => $this->to($email, $name),
            'cc' => $this->cc($email, $name),
            'bcc' => $this->bcc($email, $name),
            default => null,
        };
    }

    /**
     * Normalize Laravel's already-validated address arrays for the snapshot.
     *
     * @param  array<array-key, mixed>  $recipients
     * @return list<Address>
     */
    private function mailTrackingFailureAddresses(array $recipients): array
    {
        $addresses = [];

        foreach ($recipients as $recipient) {
            if (! is_array($recipient)) {
                continue;
            }

            $email = $this->mailTrackingFailureString(
                $recipient['address'] ?? null,
            );
            $name = $this->mailTrackingFailureString(
                $recipient['name'] ?? null,
            ) ?? '';

            if ($email === null) {
                continue;
            }

            try {
                $addresses[] = new Address($email, $name);
            } catch (Throwable) {
                // Invalid recipient state is omitted from the minimal snapshot.
            }
        }

        return $addresses;
    }

    /**
     * Retain string values from potentially corrupted serialized state.
     */
    private function mailTrackingFailureString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
