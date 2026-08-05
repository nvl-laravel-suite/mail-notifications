<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;
use RuntimeException;

/**
 * Simulates a transient factory failure without exposing its raw message.
 */
final class FailingScheduledTestFactory implements ScheduledMessageFactory
{
    /**
     * Return the fixture's stable alias.
     */
    public function alias(): string
    {
        return 'test.scheduled-failure';
    }

    /**
     * Support the fixture's only payload version.
     */
    public function supportsVersion(int $version): bool
    {
        return $version === 1;
    }

    /**
     * Require the fixture payload shape.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validate(int $version, array $payload): void
    {
        if ($version !== 1 || ! isset($payload['body'])) {
            throw new InvalidArgumentException(
                'The failing fixture requires a body.',
            );
        }
    }

    /**
     * Simulate a transient host failure.
     */
    public function make(ScheduledMessageData $message): Mailable
    {
        throw new RuntimeException(
            'raw provider secret must never be persisted',
        );
    }
}
