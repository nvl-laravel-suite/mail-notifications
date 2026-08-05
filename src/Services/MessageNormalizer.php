<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Nvl\MailNotifications\ValueObjects\PreparedMessage;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\TrackingContext;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Normalizes effective Symfony message addresses into package value objects.
 */
final readonly class MessageNormalizer
{
    /**
     * Create the effective message normalizer.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Normalize the effective message observed by Laravel immediately before send.
     */
    public function normalize(
        Email $message,
        string $correlationId,
        string $mailer,
        TrackingContext $context,
        ?string $queueReference = null,
    ): PreparedMessage {
        return $this->preparedMessage(
            message: $message,
            correlationId: $correlationId,
            mailer: $mailer,
            context: $context,
            queueReference: $queueReference,
        );
    }

    /**
     * Normalize a body-free queued-failure snapshot with the configured sender fallback.
     */
    public function normalizeQueuedFailure(
        Email $message,
        string $correlationId,
        string $mailer,
        TrackingContext $context,
        string $queueReference,
    ): PreparedMessage {
        return $this->preparedMessage(
            message: $message,
            correlationId: $correlationId,
            mailer: $mailer,
            context: $context,
            queueReference: $queueReference,
            useConfiguredFrom: true,
        );
    }

    /**
     * Build one provider-neutral message snapshot.
     */
    private function preparedMessage(
        Email $message,
        string $correlationId,
        string $mailer,
        TrackingContext $context,
        ?string $queueReference,
        bool $useConfiguredFrom = false,
    ): PreparedMessage {
        $from = $message->getFrom()[0] ?? null;
        $storeSubject = $this->storeSubject();
        $normalizedFrom = $from instanceof Address
            ? $this->recipient($from)
            : ($useConfiguredFrom ? $this->configuredFrom() : null);

        return new PreparedMessage(
            correlationId: $correlationId,
            mailer: $mailer,
            context: $context,
            from: $normalizedFrom,
            to: $this->recipients($message->getTo()),
            cc: $this->recipients($message->getCc()),
            bcc: $this->recipients($message->getBcc()),
            subject: $storeSubject ? $message->getSubject() : null,
            queueReference: $queueReference,
        );
    }

    /**
     * Resolve the configured sender when Laravel failed before creating its message.
     */
    private function configuredFrom(): ?Recipient
    {
        $address = $this->config->get('mail.from.address');
        $name = $this->config->get('mail.from.name');

        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        try {
            return new Recipient(
                email: $address,
                name: is_string($name) ? $name : null,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Normalize one Symfony address.
     */
    private function recipient(Address $address): Recipient
    {
        return new Recipient(
            email: $address->getAddress(),
            name: $address->getName(),
        );
    }

    /**
     * Normalize a list of Symfony addresses.
     *
     * @param  array<array-key, Address>  $addresses
     * @return list<Recipient>
     */
    private function recipients(array $addresses): array
    {
        $recipients = [];

        foreach ($addresses as $address) {
            $recipients[] = $this->recipient($address);
        }

        return $recipients;
    }

    /**
     * Determine whether tracked subjects may be persisted.
     */
    private function storeSubject(): bool
    {
        $value = $this->config->get(
            'mail-notifications.tracking.store_subject',
            true,
        );

        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                'Mail notification subject storage must be configured with a boolean.',
            );
        }

        return $value;
    }
}
