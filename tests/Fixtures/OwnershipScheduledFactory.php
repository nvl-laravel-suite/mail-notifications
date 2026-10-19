<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;
use Nvl\Support\Ownership\Contracts\OwnershipContext;

/** Captures construction scope so the registry test can detect a retained factory instance. */
final readonly class OwnershipScheduledFactory implements ScheduledMessageFactory
{
    public ?string $createdForOwner;

    public function __construct(OwnershipContext $context)
    {
        $this->createdForOwner = $context->snapshot()->owner?->key;
    }

    public function alias(): string
    {
        return 'native.scheduled';
    }

    public function supportsVersion(int $version): bool
    {
        return $version === 1;
    }

    /** @param array<string,mixed> $payload */
    public function validate(int $version, array $payload): void
    {
        if ($version !== 1 || ! is_string($payload['body'] ?? null)) {
            throw new \InvalidArgumentException('Native mail requires its supported body payload.');
        }
    }

    public function make(ScheduledMessageData $message): Mailable
    {
        $body = $message->payload['body'] ?? null;
        if (! is_string($body)) {
            throw new \InvalidArgumentException('Native mail requires a body string.');
        }

        return (new Mailable)->html($body)->subject('Native scheduled mail')->from('sender@example.test');
    }
}
