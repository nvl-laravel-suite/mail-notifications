<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use InvalidArgumentException;

/**
 * Carries normalized TO, CC, and BCC recipients for scheduled delivery.
 */
final readonly class ScheduledRecipients
{
    /**
     * Normalized TO recipients.
     *
     * @var list<Recipient>
     */
    public array $to;

    /**
     * Normalized CC recipients.
     *
     * @var list<Recipient>
     */
    public array $cc;

    /**
     * Normalized BCC recipients.
     *
     * @var list<Recipient>
     */
    public array $bcc;

    /**
     * Create a normalized recipient envelope.
     *
     * @param  list<Recipient>  $to
     * @param  list<Recipient>  $cc
     * @param  list<Recipient>  $bcc
     */
    public function __construct(
        array $to,
        array $cc = [],
        array $bcc = [],
    ) {
        $normalizedTo = $this->normalize($to);

        if ($normalizedTo === []) {
            throw new InvalidArgumentException(
                'Scheduled mail requires at least one TO recipient.',
            );
        }

        $normalizedCc = $this->withoutEmails(
            $this->normalize($cc),
            $normalizedTo,
        );
        $normalizedBcc = $this->withoutEmails(
            $this->normalize($bcc),
            [...$normalizedTo, ...$normalizedCc],
        );

        $this->to = $normalizedTo;
        $this->cc = $normalizedCc;
        $this->bcc = $normalizedBcc;
    }

    /**
     * Restore a normalized envelope from persisted recipient arrays.
     *
     * @param  array<array-key, mixed>  $to
     * @param  array<array-key, mixed>  $cc
     * @param  array<array-key, mixed>  $bcc
     */
    public static function fromPersisted(
        array $to,
        array $cc = [],
        array $bcc = [],
    ): self {
        return new self(
            to: self::restore($to),
            cc: self::restore($cc),
            bcc: self::restore($bcc),
        );
    }

    /**
     * Return the persisted TO representation.
     *
     * @return list<array{email: string, name: string|null}>
     */
    public function toPayload(): array
    {
        return array_map(
            static fn (Recipient $recipient): array => $recipient->toArray(),
            $this->to,
        );
    }

    /**
     * Return the persisted CC representation.
     *
     * @return list<array{email: string, name: string|null}>
     */
    public function ccPayload(): array
    {
        return array_map(
            static fn (Recipient $recipient): array => $recipient->toArray(),
            $this->cc,
        );
    }

    /**
     * Return the persisted BCC representation.
     *
     * @return list<array{email: string, name: string|null}>
     */
    public function bccPayload(): array
    {
        return array_map(
            static fn (Recipient $recipient): array => $recipient->toArray(),
            $this->bcc,
        );
    }

    /**
     * Normalize and deduplicate one recipient list.
     *
     * @param  list<Recipient>  $recipients
     * @return list<Recipient>
     */
    private function normalize(array $recipients): array
    {
        $normalized = [];

        foreach ($recipients as $recipient) {
            $normalized[mb_strtolower($recipient->email)] = $recipient;
        }

        return array_values($normalized);
    }

    /**
     * Remove recipients already present in a higher-precedence list.
     *
     * @param  list<Recipient>  $recipients
     * @param  list<Recipient>  $excluded
     * @return list<Recipient>
     */
    private function withoutEmails(array $recipients, array $excluded): array
    {
        $excludedEmails = array_fill_keys(array_map(
            static fn (Recipient $recipient): string => mb_strtolower(
                $recipient->email,
            ),
            $excluded,
        ), true);

        return array_values(array_filter(
            $recipients,
            static fn (Recipient $recipient): bool => ! isset(
                $excludedEmails[mb_strtolower($recipient->email)],
            ),
        ));
    }

    /**
     * Restore Recipient value objects from persisted data.
     *
     * @param  array<array-key, mixed>  $recipients
     * @return list<Recipient>
     */
    private static function restore(array $recipients): array
    {
        return array_map(static function (mixed $recipient): Recipient {
            if (! is_array($recipient)
                || ! isset($recipient['email'])
                || ! is_string($recipient['email'])) {
                throw new InvalidArgumentException(
                    'Persisted scheduled mail recipients are invalid.',
                );
            }

            $name = $recipient['name'] ?? null;

            if ($name !== null && ! is_string($name)) {
                throw new InvalidArgumentException(
                    'Persisted scheduled mail recipient names are invalid.',
                );
            }

            return new Recipient(
                email: $recipient['email'],
                name: $name,
            );
        }, array_values($recipients));
    }
}
