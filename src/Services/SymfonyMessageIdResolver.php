<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Contracts\ProviderMessageIdResolver;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\MailNotifications\ValueObjects\TransportResult;

/**
 * Resolves the standard Symfony message identifier for any Laravel transport.
 */
final readonly class SymfonyMessageIdResolver implements ProviderMessageIdResolver
{
    /**
     * Create the framework transport resolver.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Determine whether the standard resolver can inspect this transport result.
     */
    public function supports(TransportResult $result): bool
    {
        return trim($result->message->getMessageId()) !== '';
    }

    /**
     * Resolve a provider-neutral message identity from Symfony.
     */
    public function resolve(TransportResult $result): ?ProviderMessageId
    {
        $messageId = trim($result->message->getMessageId());

        if ($messageId === '') {
            return null;
        }

        $provider = $this->providerForMailer($result->mailer);

        return new ProviderMessageId($provider, $messageId);
    }

    /**
     * Validate configured provider aliases before tracking delivery.
     */
    public function validateConfiguration(): void
    {
        $mailerProviders = $this->config->get(
            'mail-notifications.providers.mailers',
            [],
        );

        if (! is_array($mailerProviders)) {
            throw new MailTrackingException(
                'Mail notification provider mailer mappings must be an array.',
            );
        }

        foreach ($mailerProviders as $mailer => $provider) {
            if (! is_string($mailer)
                || trim($mailer) === ''
                || $mailer !== trim($mailer)
                || mb_strlen(trim($mailer)) > 128
                || ! is_string($provider)
                || trim($provider) === ''
                || mb_strlen(trim($provider)) > 128) {
                throw new MailTrackingException(
                    'Mail notification provider mailer mappings require names containing 1 to 128 characters.',
                );
            }
        }

        $defaultProvider = $this->config->get(
            'mail-notifications.providers.default',
        );

        if ($defaultProvider === null || $defaultProvider === '') {
            return;
        }

        if (! is_string($defaultProvider)
            || mb_strlen(trim($defaultProvider)) > 128
            || trim($defaultProvider) === '') {
            throw new MailTrackingException(
                'The default mail notification provider must contain 1 to 128 characters.',
            );
        }
    }

    /**
     * Resolve the configured provider identity for a Laravel mailer.
     */
    private function providerForMailer(string $mailer): string
    {
        $this->validateConfiguration();
        $mailerProviders = $this->config->get(
            'mail-notifications.providers.mailers',
            [],
        );
        $defaultProvider = $this->config->get(
            'mail-notifications.providers.default',
        );
        $configuredProvider = is_array($mailerProviders)
            ? ($mailerProviders[$mailer] ?? null)
            : null;

        if (is_string($configuredProvider)) {
            return trim($configuredProvider);
        }

        return is_string($defaultProvider) && trim($defaultProvider) !== ''
            ? trim($defaultProvider)
            : $mailer;
    }
}
