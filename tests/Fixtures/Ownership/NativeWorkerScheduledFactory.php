<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures\Ownership;

use Illuminate\Mail\Mailable;
use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** Reconstructs actual private native mail after a fresh command producer admission. */
final readonly class NativeWorkerScheduledFactory implements ScheduledMessageFactory
{
    public function __construct(private OwnershipContext $context, private OwnershipQueueContext $queue) {}

    public function alias(): string
    {
        return 'native.worker';
    }

    public function supportsVersion(int $version): bool
    {
        return $version === 1;
    }

    /** @param array<string,mixed> $payload */
    public function validate(int $version, array $payload): void
    {
        if ($version !== 1 || ! is_string($payload['body'] ?? null)) {
            throw new InvalidArgumentException;
        }
    }

    public function make(ScheduledMessageData $message): Mailable
    {
        $body = $message->payload['body'] ?? null;
        if (! is_string($body)) {
            throw new InvalidArgumentException;
        }
        $capture = $this->context->snapshot()->mode === OwnershipContextMode::Unresolved
            ? $this->queue->captureUnowned() : OwnershipJobEnvelope::capture($this->context);

        return new NativeWorkerMail($capture, $body);
    }
}
