<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Adapters\MailerSend;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Exceptions\MailTrackingException;

/**
 * Validates the opt-in MailerSend remote webhook management boundary.
 */
final readonly class MailerSendWebhookManagementConfiguration
{
    private const string CONFIG_PREFIX = 'mail-notifications.providers.mailersend.management';

    /**
     * @var list<string>
     */
    private const array SUPPORTED_EVENTS = [
        'activity.sent',
        'activity.delivered',
        'activity.deferred',
        'activity.opened',
        'activity.opened_unique',
        'activity.clicked',
        'activity.clicked_unique',
        'activity.soft_bounced',
        'activity.hard_bounced',
        'activity.unsubscribed',
        'activity.spam_complaint',
    ];

    /**
     * Create the MailerSend management configuration reader.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Determine whether remote management is explicitly enabled.
     */
    public function enabled(): bool
    {
        $enabled = $this->config->get(self::CONFIG_PREFIX.'.enabled', false);

        if (! is_bool($enabled)) {
            throw new MailTrackingException(
                'MailerSend webhook management [enabled] must be boolean.',
            );
        }

        return $enabled;
    }

    /**
     * Validate every enabled management setting without making an HTTP request.
     */
    public function validate(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $packageEnabled = $this->boolean(
            'mail-notifications.enabled',
            true,
        );
        $webhooksEnabled = $this->boolean(
            'mail-notifications.webhooks.enabled',
            true,
        );

        if (! $packageEnabled || ! $webhooksEnabled) {
            throw new MailTrackingException(
                'MailerSend webhook management requires package webhook processing to be enabled.',
            );
        }

        $this->token();
        $this->domainId();
        $this->apiUrl();
        $timeoutSeconds = $this->timeoutSeconds();
        $connectTimeoutSeconds = $this->connectTimeoutSeconds();

        if ($connectTimeoutSeconds > $timeoutSeconds) {
            throw new MailTrackingException(
                'MailerSend webhook management [connect_timeout_seconds] cannot exceed [timeout_seconds].',
            );
        }

        $this->pageSize();
        $this->maximumPages();
        $this->desiredWebhook();
    }

    /**
     * Return the MailerSend API bearer token.
     */
    public function token(): string
    {
        return $this->requiredString('token', 4_096);
    }

    /**
     * Return the existing MailerSend sending-domain identifier.
     */
    public function domainId(): string
    {
        return $this->requiredString('domain_id', 255);
    }

    /**
     * Return the validated HTTPS MailerSend API base URL.
     */
    public function apiUrl(): string
    {
        $url = rtrim($this->requiredString('api_url', 191), '/');
        $this->assertHttpsUrl($url, 'API base URL');

        return $url;
    }

    /**
     * Return the bounded HTTP request timeout.
     */
    public function timeoutSeconds(): int
    {
        return $this->boundedInteger('timeout_seconds', 1, 60);
    }

    /**
     * Return the bounded HTTP connection timeout.
     */
    public function connectTimeoutSeconds(): int
    {
        return $this->boundedInteger('connect_timeout_seconds', 1, 60);
    }

    /**
     * Return the provider-supported page size.
     */
    public function pageSize(): int
    {
        return $this->boundedInteger('pagination.page_size', 10, 100);
    }

    /**
     * Return the hard pagination request bound.
     */
    public function maximumPages(): int
    {
        return $this->boundedInteger('pagination.max_pages', 1, 100);
    }

    /**
     * Return the exact desired v2 webhook definition.
     *
     * @return array{
     *     name: string,
     *     url: string,
     *     events: list<string>,
     *     enabled: bool,
     *     version: int
     * }
     */
    public function desiredWebhook(): array
    {
        $name = $this->requiredString('webhook.name', 50);
        $url = $this->requiredString('webhook.url', 191);
        $this->assertHttpsUrl($url, 'callback URL');
        $events = $this->events();
        $enabled = $this->config->get(
            self::CONFIG_PREFIX.'.webhook.enabled',
            true,
        );
        $version = $this->config->get(
            self::CONFIG_PREFIX.'.webhook.version',
            2,
        );

        if (! is_bool($enabled)) {
            throw new MailTrackingException(
                'MailerSend managed webhook [enabled] must be boolean.',
            );
        }

        if ($version !== 2) {
            throw new MailTrackingException(
                'MailerSend managed webhook [version] must be the recommended version [2].',
            );
        }

        return [
            'name' => $name,
            'url' => $url,
            'events' => $events,
            'enabled' => $enabled,
            'version' => $version,
        ];
    }

    /**
     * Return an allowlisted, duplicate-free set of supported activity events.
     *
     * @return list<string>
     */
    private function events(): array
    {
        $events = $this->config->get(
            self::CONFIG_PREFIX.'.webhook.events',
            self::SUPPORTED_EVENTS,
        );

        if (! is_array($events) || ! array_is_list($events) || $events === []) {
            throw new MailTrackingException(
                'MailerSend managed webhook [events] must be a non-empty list.',
            );
        }

        $normalized = [];

        foreach ($events as $event) {
            if (! is_string($event)
                || ! in_array($event, self::SUPPORTED_EVENTS, true)) {
                throw new MailTrackingException(
                    'MailerSend managed webhook [events] contains an unsupported activity event.',
                );
            }

            $normalized[] = $event;
        }

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new MailTrackingException(
                'MailerSend managed webhook [events] cannot contain duplicates.',
            );
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * Return one required trimmed management string.
     */
    private function requiredString(string $key, int $maximumLength): string
    {
        $value = $this->config->get(self::CONFIG_PREFIX.'.'.$key);

        if (! is_string($value)
            || trim($value) === ''
            || mb_strlen(trim($value)) > $maximumLength) {
            throw new MailTrackingException(
                "MailerSend webhook management [{$key}] must be a non-empty string no longer than {$maximumLength} characters.",
            );
        }

        return trim($value);
    }

    /**
     * Return one bounded integer management setting.
     */
    private function boundedInteger(string $key, int $minimum, int $maximum): int
    {
        $value = $this->config->get(self::CONFIG_PREFIX.'.'.$key);

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new MailTrackingException(
                "MailerSend webhook management [{$key}] must be an integer between {$minimum} and {$maximum}.",
            );
        }

        return $value;
    }

    /**
     * Read one package switch without unsafe truthy-value coercion.
     */
    private function boolean(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);

        if (! is_bool($value)) {
            throw new MailTrackingException(
                "Mail notification configuration [{$key}] must be a boolean.",
            );
        }

        return $value;
    }

    /**
     * Require an absolute HTTPS URL without credentials, query, or fragment.
     */
    private function assertHttpsUrl(string $url, string $label): void
    {
        $parts = parse_url($url);

        if (strlen($url) > 191
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new MailTrackingException(
                "MailerSend webhook management {$label} must be an absolute HTTPS URL no longer than 191 bytes and without credentials, query, or fragment.",
            );
        }
    }
}
