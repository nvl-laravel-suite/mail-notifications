<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;

/**
 * Rebuilds the successful scheduled-mail test fixture.
 */
final class ScheduledTestFactory implements ScheduledMessageFactory
{
    /**
     * Return the fixture's stable alias.
     */
    public function alias(): string
    {
        return 'test.scheduled';
    }

    /**
     * Support the fixture's only payload version.
     */
    public function supportsVersion(int $version): bool
    {
        return $version === 1;
    }

    /**
     * Require a body string in the fixture payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validate(int $version, array $payload): void
    {
        if ($version !== 1
            || ! isset($payload['body'])
            || ! is_string($payload['body'])) {
            throw new InvalidArgumentException(
                'The scheduled fixture requires a body string.',
            );
        }
    }

    /**
     * Rebuild the fixture Mailable.
     */
    public function make(ScheduledMessageData $message): Mailable
    {
        $body = $message->payload['body'];

        if (! is_string($body)) {
            throw new InvalidArgumentException(
                'The scheduled fixture body must be a string.',
            );
        }

        return new ScheduledTestMail($body);
    }
}
