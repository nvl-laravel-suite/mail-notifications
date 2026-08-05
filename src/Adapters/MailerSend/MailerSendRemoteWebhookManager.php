<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Adapters\MailerSend;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Nvl\MailNotifications\Contracts\RemoteWebhookManager;
use Nvl\MailNotifications\Contracts\WebhookEventNormalizer;
use Nvl\MailNotifications\Contracts\WebhookSignatureVerifier;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\Services\ProviderRegistry;
use Nvl\MailNotifications\ValueObjects\RemoteWebhookManagementResult;
use Nvl\MailNotifications\ValueObjects\RemoteWebhookRemoveOptions;
use Nvl\MailNotifications\ValueObjects\RemoteWebhookSyncOptions;

/**
 * Manages MailerSend webhooks through its v1 HTTP API without an SDK dependency.
 */
final readonly class MailerSendRemoteWebhookManager implements RemoteWebhookManager
{
    private const string PROVIDER = 'mailersend';

    /**
     * Create the explicit operator-only MailerSend manager.
     */
    public function __construct(
        private Factory $http,
        private MailerSendWebhookManagementConfiguration $configuration,
        private ProviderRegistry $providers,
    ) {}

    /**
     * Return the stable provider name used by operator commands.
     */
    public function provider(): string
    {
        return self::PROVIDER;
    }

    /**
     * Determine whether remote management is explicitly enabled.
     */
    public function enabled(): bool
    {
        return $this->configuration->enabled();
    }

    /**
     * Validate enabled configuration without making a network request.
     */
    public function validateConfiguration(): void
    {
        $this->configuration->validate();

        if (! $this->enabled()) {
            return;
        }

        $adapter = $this->providers->all()[self::PROVIDER] ?? null;

        if (! $adapter instanceof WebhookSignatureVerifier
            || ! $adapter instanceof WebhookEventNormalizer) {
            throw new MailTrackingException(
                'Enabled remote webhook manager [mailersend] requires a same-name provider adapter with verification and normalization capabilities.',
            );
        }
    }

    /**
     * Create, compare, or explicitly update the configured remote webhook.
     */
    public function sync(
        RemoteWebhookSyncOptions $options,
    ): RemoteWebhookManagementResult {
        $this->requireEnabledConfiguration();
        $desired = $this->configuration->desiredWebhook();
        $matches = array_values(array_filter(
            $this->webhooks(),
            static fn (array $webhook): bool => $webhook['name'] === $desired['name'],
        ));

        if (count($matches) > 1) {
            return $this->result(
                operation: 'sync',
                failed: 1,
                dryRun: $options->dryRun,
                errors: [
                    'Multiple remote webhooks match the configured managed name; resolve the ambiguity before syncing.',
                ],
            );
        }

        if ($matches === []) {
            if ($options->dryRun) {
                return $this->result(
                    operation: 'sync',
                    planned: 1,
                    dryRun: true,
                );
            }

            $response = $this->send(
                fn (PendingRequest $request): Response => $request->post(
                    '/webhooks',
                    [
                        ...$desired,
                        'domain_id' => $this->configuration->domainId(),
                    ],
                ),
            );

            return $response->successful()
                ? $this->result(operation: 'sync', planned: 1, changed: 1)
                : $this->apiFailure('sync', 'create', $response);
        }

        $existing = $matches[0];

        if ($this->matchesDesired($existing, $desired)) {
            return $this->result(
                operation: 'sync',
                unchanged: 1,
                dryRun: $options->dryRun,
            );
        }

        if (! $options->force) {
            return $this->result(
                operation: 'sync',
                failed: 1,
                dryRun: $options->dryRun,
                errors: [
                    'The managed remote webhook differs from configuration; inspect a dry run and rerun with --force to update it.',
                ],
            );
        }

        if ($options->dryRun) {
            return $this->result(
                operation: 'sync',
                planned: 1,
                dryRun: true,
            );
        }

        $response = $this->send(
            static fn (PendingRequest $request): Response => $request->put(
                '/webhooks/'.rawurlencode($existing['id']),
                $desired,
            ),
        );

        return $response->successful()
            ? $this->result(operation: 'sync', planned: 1, changed: 1)
            : $this->apiFailure('sync', 'update', $response);
    }

    /**
     * Remove the unique configured-name webhook or explicitly every domain webhook.
     */
    public function remove(
        RemoteWebhookRemoveOptions $options,
    ): RemoteWebhookManagementResult {
        $this->requireEnabledConfiguration();
        $webhooks = $this->webhooks();

        if ($options->all) {
            $targets = $webhooks;
        } else {
            $managedName = $this->configuration->desiredWebhook()['name'];
            $targets = array_values(array_filter(
                $webhooks,
                static fn (array $webhook): bool => $webhook['name'] === $managedName,
            ));

            if (count($targets) > 1) {
                return $this->result(
                    operation: 'remove',
                    failed: 1,
                    dryRun: $options->dryRun,
                    errors: [
                        'Multiple remote webhooks match the configured managed name; use --all only after reviewing the full domain scope.',
                    ],
                );
            }
        }

        if ($targets === []) {
            return $this->result(
                operation: 'remove',
                unchanged: 1,
                dryRun: $options->dryRun,
            );
        }

        if ($options->dryRun) {
            return $this->result(
                operation: 'remove',
                planned: count($targets),
                dryRun: true,
            );
        }

        $changed = 0;
        $unchanged = 0;
        $failed = 0;
        $errors = [];

        foreach ($targets as $target) {
            $response = $this->send(
                static fn (PendingRequest $request): Response => $request->delete(
                    '/webhooks/'.rawurlencode($target['id']),
                ),
            );

            if ($response->successful()) {
                $changed++;

                continue;
            }

            if ($response->status() === 404) {
                $unchanged++;

                continue;
            }

            $failed++;

            if (count($errors) < 20) {
                $errors[] = sprintf(
                    'MailerSend webhook delete request failed with HTTP status [%d].',
                    $response->status(),
                );
            }
        }

        return $this->result(
            operation: 'remove',
            planned: count($targets),
            changed: $changed,
            unchanged: $unchanged,
            failed: $failed,
            errors: $errors,
        );
    }

    /**
     * Require the explicit management switch and every local setting.
     */
    private function requireEnabledConfiguration(): void
    {
        if (! $this->enabled()) {
            throw new MailTrackingException(
                'MailerSend remote webhook management is disabled.',
            );
        }

        $this->validateConfiguration();
    }

    /**
     * Retrieve and parse a complete bounded domain-scoped webhook list.
     *
     * @return list<array{
     *     id: string,
     *     name: string,
     *     url: string,
     *     events: list<string>,
     *     enabled: bool,
     *     version: int
     * }>
     */
    private function webhooks(): array
    {
        $webhooks = [];
        $maximumPages = $this->configuration->maximumPages();
        $pageSize = $this->configuration->pageSize();

        for ($page = 1; $page <= $maximumPages; $page++) {
            $response = $this->send(
                fn (PendingRequest $request): Response => $request->get(
                    '/webhooks',
                    [
                        'domain_id' => $this->configuration->domainId(),
                        'limit' => $pageSize,
                        'page' => $page,
                    ],
                ),
            );

            if (! $response->successful()) {
                throw new MailTrackingException(sprintf(
                    'MailerSend webhook list request failed with HTTP status [%d].',
                    $response->status(),
                ));
            }

            $payload = $response->json();

            if (! is_array($payload)
                || ! isset($payload['data'])
                || ! is_array($payload['data'])
                || ! array_is_list($payload['data'])) {
                throw new MailTrackingException(
                    'MailerSend webhook list response has an invalid shape.',
                );
            }

            foreach ($payload['data'] as $webhook) {
                $webhooks[] = $this->parseWebhook($webhook);
            }

            $meta = $payload['meta'] ?? null;

            if ($meta !== null && ! is_array($meta)) {
                throw new MailTrackingException(
                    'MailerSend webhook list pagination metadata is invalid.',
                );
            }

            $lastPage = is_array($meta) ? ($meta['last_page'] ?? null) : null;

            if ($lastPage !== null
                && (! is_int($lastPage) || $lastPage < $page)) {
                throw new MailTrackingException(
                    'MailerSend webhook list pagination metadata is invalid.',
                );
            }

            if ((is_int($lastPage) && $page >= $lastPage)
                || count($payload['data']) < $pageSize) {
                return $webhooks;
            }
        }

        throw new MailTrackingException(
            'MailerSend webhook listing exceeded the configured pagination bound.',
        );
    }

    /**
     * Parse only fields needed for idempotent comparison and deletion.
     *
     * @return array{
     *     id: string,
     *     name: string,
     *     url: string,
     *     events: list<string>,
     *     enabled: bool,
     *     version: int
     * }
     */
    private function parseWebhook(mixed $webhook): array
    {
        if (! is_array($webhook)
            || ! is_string($webhook['id'] ?? null)
            || trim($webhook['id']) === ''
            || mb_strlen(trim($webhook['id'])) > 255
            || ! is_string($webhook['name'] ?? null)
            || mb_strlen($webhook['name']) > 50
            || ! is_string($webhook['url'] ?? null)
            || ! is_array($webhook['events'] ?? null)
            || ! array_is_list($webhook['events'])
            || ! is_bool($webhook['enabled'] ?? null)) {
            throw new MailTrackingException(
                'MailerSend webhook list response contains an invalid webhook record.',
            );
        }

        $version = $webhook['version'] ?? 1;

        if (! is_int($version) || ! in_array($version, [1, 2], true)) {
            throw new MailTrackingException(
                'MailerSend webhook list response contains an invalid webhook version.',
            );
        }

        $events = [];

        foreach ($webhook['events'] as $event) {
            if (! is_string($event) || mb_strlen($event) > 128) {
                throw new MailTrackingException(
                    'MailerSend webhook list response contains invalid events.',
                );
            }

            $events[] = $event;
        }

        sort($events);

        return [
            'id' => trim($webhook['id']),
            'name' => $webhook['name'],
            'url' => $webhook['url'],
            'events' => $events,
            'enabled' => $webhook['enabled'],
            'version' => $version,
        ];
    }

    /**
     * Determine whether a remote record already equals the desired definition.
     *
     * @param  array{
     *     id: string,
     *     name: string,
     *     url: string,
     *     events: list<string>,
     *     enabled: bool,
     *     version: int
     * }  $existing
     * @param  array{
     *     name: string,
     *     url: string,
     *     events: list<string>,
     *     enabled: bool,
     *     version: int
     * }  $desired
     */
    private function matchesDesired(array $existing, array $desired): bool
    {
        return $existing['name'] === $desired['name']
            && rtrim($existing['url'], '/') === rtrim($desired['url'], '/')
            && $existing['events'] === $desired['events']
            && $existing['enabled'] === $desired['enabled']
            && $existing['version'] === $desired['version'];
    }

    /**
     * Send one authenticated API request and sanitize connection failures.
     *
     * @param  callable(PendingRequest): Response  $callback
     */
    private function send(callable $callback): Response
    {
        try {
            return $callback($this->request());
        } catch (ConnectionException $exception) {
            throw new MailTrackingException(
                'MailerSend webhook API connection failed.',
                previous: $exception,
            );
        }
    }

    /**
     * Create a fresh authenticated request without making network calls.
     */
    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->configuration->apiUrl())
            ->withToken($this->configuration->token())
            ->acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->connectTimeout(
                $this->configuration->connectTimeoutSeconds(),
            )
            ->timeout($this->configuration->timeoutSeconds());
    }

    /**
     * Return a sanitized one-request API failure result.
     */
    private function apiFailure(
        string $operation,
        string $request,
        Response $response,
    ): RemoteWebhookManagementResult {
        return $this->result(
            operation: $operation,
            planned: 1,
            failed: 1,
            errors: [sprintf(
                'MailerSend webhook %s request failed with HTTP status [%d].',
                $request,
                $response->status(),
            )],
        );
    }

    /**
     * Create one bounded MailerSend management result.
     *
     * @param  list<string>  $errors
     */
    private function result(
        string $operation,
        int $planned = 0,
        int $changed = 0,
        int $unchanged = 0,
        int $failed = 0,
        bool $dryRun = false,
        array $errors = [],
    ): RemoteWebhookManagementResult {
        return new RemoteWebhookManagementResult(
            provider: self::PROVIDER,
            operation: $operation,
            planned: $planned,
            changed: $changed,
            unchanged: $unchanged,
            failed: $failed,
            dryRun: $dryRun,
            errors: $errors,
        );
    }
}
