<?php

declare(strict_types=1);

use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Nvl\MailNotifications\Contracts\ProviderMessageIdResolver;
use Nvl\MailNotifications\Services\ProviderMessageIdRegistry;
use Nvl\MailNotifications\Services\SymfonyMessageIdResolver;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\TransportResult;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Create a completed transport result for registry tests.
 */
function createNvlTransportResult(string $mailer = 'smtp'): TransportResult
{
    $email = (new Email)
        ->from('sender@example.test')
        ->to('recipient@example.test')
        ->subject('Registry test')
        ->text('Registry test');
    $envelope = new Envelope(
        new Address('sender@example.test'),
        [new Address('recipient@example.test')],
    );

    return new TransportResult(
        mailer: $mailer,
        message: new LaravelSentMessage(
            new SymfonySentMessage($email, $envelope),
        ),
    );
}

it('prefers the first provider-specific message identifier resolver', function () {
    $resolver = new class implements ProviderMessageIdResolver
    {
        public function supports(TransportResult $result): bool
        {
            return $result->mailer === 'smtp';
        }

        public function resolve(TransportResult $result): ?ProviderMessageId
        {
            return new ProviderMessageId('provider-adapter', 'provider-message-1');
        }
    };
    $registry = new ProviderMessageIdRegistry(
        resolvers: [$resolver],
        fallback: app(SymfonyMessageIdResolver::class),
    );

    expect($registry->resolve(createNvlTransportResult()))
        ->provider->toBe('provider-adapter')
        ->value->toBe('provider-message-1');
});

it('falls back to the Symfony transport identifier', function () {
    config()->set('mail-notifications.providers.default', 'generic-smtp');
    $resolver = new class implements ProviderMessageIdResolver
    {
        public function supports(TransportResult $result): bool
        {
            return true;
        }

        public function resolve(TransportResult $result): ?ProviderMessageId
        {
            return null;
        }
    };
    $registry = new ProviderMessageIdRegistry(
        resolvers: [$resolver],
        fallback: app(SymfonyMessageIdResolver::class),
    );

    expect($registry->resolve(createNvlTransportResult()))
        ->provider->toBe('generic-smtp')
        ->value->not->toBeEmpty();
});

it('rejects invalid provider message identifier resolvers', function () {
    expect(fn () => new ProviderMessageIdRegistry(
        resolvers: [new stdClass],
        fallback: app(SymfonyMessageIdResolver::class),
    ))->toThrow(UnexpectedValueException::class);
});
