<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Nvl\MailNotifications\Adapters\MailerSend\MailerSendAdapter;
use Nvl\MailNotifications\Adapters\MailerSend\MailerSendRemoteWebhookManager;
use Nvl\MailNotifications\Contracts\ProviderAdapter;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\Services\ProviderRegistry;
use Nvl\MailNotifications\Services\RemoteWebhookManagerRegistry;

/**
 * Return the supported MailerSend activity event set.
 *
 * @return list<string>
 */
function managedMailerSendEvents(): array
{
    return [
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
}

/**
 * Configure and register the opt-in MailerSend adapter and remote manager.
 *
 * @param  array<string, mixed>  $overrides
 */
function configureMailerSendWebhookManagement(
    array $overrides = [],
    bool $registerAdapter = true,
): void {
    config()->set([
        'mail-notifications.extensions.provider_adapters' => $registerAdapter
            ? [MailerSendAdapter::class]
            : [],
        'mail-notifications.extensions.webhook_managers' => [
            MailerSendRemoteWebhookManager::class,
        ],
        'mail-notifications.providers.mailersend.signing_secret' => 'activity-signing-secret',
        'mail-notifications.providers.mailersend.management.enabled' => true,
        'mail-notifications.providers.mailersend.management.token' => 'api-token-secret',
        'mail-notifications.providers.mailersend.management.domain_id' => 'domain-123',
        'mail-notifications.providers.mailersend.management.api_url' => 'https://api.mailersend.test/v1',
        'mail-notifications.providers.mailersend.management.timeout_seconds' => 10,
        'mail-notifications.providers.mailersend.management.connect_timeout_seconds' => 3,
        'mail-notifications.providers.mailersend.management.pagination.page_size' => 100,
        'mail-notifications.providers.mailersend.management.pagination.max_pages' => 10,
        'mail-notifications.providers.mailersend.management.webhook.name' => 'Mail Notifications',
        'mail-notifications.providers.mailersend.management.webhook.url' => 'https://app.example.test/webhooks/mailersend',
        'mail-notifications.providers.mailersend.management.webhook.events' => managedMailerSendEvents(),
        'mail-notifications.providers.mailersend.management.webhook.enabled' => true,
        'mail-notifications.providers.mailersend.management.webhook.version' => 2,
    ]);

    foreach ($overrides as $key => $value) {
        config()->set(
            'mail-notifications.providers.mailersend.management.'.$key,
            $value,
        );
    }

    (new MailNotificationsServiceProvider(app()))->register();
}

/**
 * Build one MailerSend API webhook record.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function remoteMailerSendWebhook(array $overrides = []): array
{
    return [
        'id' => 'webhook-123',
        'name' => 'Mail Notifications',
        'url' => 'https://app.example.test/webhooks/mailersend',
        'events' => managedMailerSendEvents(),
        'enabled' => true,
        'version' => 2,
        ...$overrides,
    ];
}

/**
 * Build one paginated MailerSend list response.
 *
 * @param  list<array<string, mixed>>  $webhooks
 * @return array<string, mixed>
 */
function remoteMailerSendWebhookList(
    array $webhooks,
    int $lastPage = 1,
): array {
    return [
        'data' => $webhooks,
        'meta' => ['last_page' => $lastPage],
    ];
}

it('performs no remote I/O while registering or resolving management services', function () {
    Http::preventStrayRequests();
    configureMailerSendWebhookManagement();

    expect(app(RemoteWebhookManagerRegistry::class)->resolve('mailersend'))
        ->toBeInstanceOf(MailerSendRemoteWebhookManager::class);
    Http::assertNothingSent();
});

it('creates a missing v2 webhook without exposing its generated secret', function () {
    Http::fake([
        'https://api.mailersend.test/v1/webhooks?*' => Http::response(
            remoteMailerSendWebhookList([]),
        ),
        'https://api.mailersend.test/v1/webhooks' => Http::response([
            'data' => [
                'id' => 'new-webhook',
                'secret' => 'generated-signing-secret',
            ],
        ], 201),
    ]);
    configureMailerSendWebhookManagement();
    config()->set('mail-notifications.providers.mailersend.signing_secret');

    $this->artisan('nvl:mail-notifications:webhooks:sync', [
        '--provider' => 'mailersend',
    ])->doesntExpectOutput('generated-signing-secret')
        ->doesntExpectOutput('api-token-secret')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.mailersend.test/v1/webhooks'
        && $request->hasHeader('Authorization', 'Bearer api-token-secret')
        && $request['domain_id'] === 'domain-123'
        && $request['version'] === 2);
});

it('treats an exact remote webhook as an idempotent no-op', function () {
    $allowRedirects = null;
    $connectTimeout = null;
    $timeout = null;
    Http::fake(static function (
        Request $request,
        array $options,
    ) use (&$allowRedirects, &$connectTimeout, &$timeout) {
        $allowRedirects = $options['allow_redirects'] ?? null;
        $connectTimeout = $options['connect_timeout'] ?? null;
        $timeout = $options['timeout'] ?? null;

        return Http::response(remoteMailerSendWebhookList([
            remoteMailerSendWebhook(),
        ]));
    });
    configureMailerSendWebhookManagement();

    $this->artisan('nvl:mail-notifications:webhooks:sync', [
        '--provider' => 'mailersend',
    ])->expectsOutputToContain('unchanged=1')
        ->assertSuccessful();

    Http::assertSentCount(1);
    expect($allowRedirects)->toBeFalse()
        ->and($connectTimeout)->toBe(3)
        ->and($timeout)->toBe(10);
});

it('normalizes callback trailing slashes during comparison', function () {
    Http::fake([
        '*' => Http::response(remoteMailerSendWebhookList([
            remoteMailerSendWebhook([
                'url' => 'https://app.example.test/webhooks/mailersend/',
            ]),
        ])),
    ]);
    configureMailerSendWebhookManagement();

    $this->artisan('nvl:mail-notifications:webhooks:sync', [
        '--provider' => 'mailersend',
    ])->assertSuccessful();

    Http::assertSentCount(1);
});

it('requires force before updating configuration drift', function () {
    Http::fake([
        '*' => Http::response(remoteMailerSendWebhookList([
            remoteMailerSendWebhook(['enabled' => false]),
        ])),
    ]);
    configureMailerSendWebhookManagement();

    $this->artisan('nvl:mail-notifications:webhooks:sync', [
        '--provider' => 'mailersend',
    ])->expectsOutputToContain('--force')
        ->assertFailed();

    Http::assertSentCount(1);
});

it('force-updates drift and upgrades legacy records with encoded IDs', function (
    array $legacyVersion,
) {
    Http::fakeSequence()
        ->push(remoteMailerSendWebhookList([
            remoteMailerSendWebhook([
                'id' => 'opaque/id',
                'enabled' => false,
                ...$legacyVersion,
            ]),
        ]))
        ->push([], 200);
    configureMailerSendWebhookManagement();

    $this->artisan('nvl:mail-notifications:webhooks:sync', [
        '--provider' => 'mailersend',
        '--force' => true,
    ])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://api.mailersend.test/v1/webhooks/opaque%2Fid'
        && $request['version'] === 2
        && $request['enabled'] === true);
})->with([
    'absent version' => [[]],
    'null version' => [['version' => null]],
]);

it('fails closed when the configured managed name is ambiguous', function () {
    Http::fake([
        '*' => Http::response(remoteMailerSendWebhookList([
            remoteMailerSendWebhook(['id' => 'duplicate-1']),
            remoteMailerSendWebhook(['id' => 'duplicate-2']),
        ])),
    ]);
    configureMailerSendWebhookManagement();

    $this->artisan('nvl:mail-notifications:webhooks:sync', [
        '--provider' => 'mailersend',
        '--force' => true,
    ])->expectsOutputToContain('Multiple remote webhooks')
        ->assertFailed();

    $this->artisan('nvl:mail-notifications:webhooks:remove', [
        '--provider' => 'mailersend',
    ])->expectsOutputToContain('Multiple remote webhooks')
        ->assertFailed();

    Http::assertSent(
        static fn (Request $request): bool => $request->method() === 'GET',
    );
});

it('scoped removal deletes only the unique configured-name webhook', function () {
    Http::fakeSequence()
        ->push(remoteMailerSendWebhookList([
            remoteMailerSendWebhook(['id' => 'managed/id']),
            remoteMailerSendWebhook([
                'id' => 'unmanaged-id',
                'name' => 'Another webhook',
            ]),
        ]))
        ->push([], 204);
    configureMailerSendWebhookManagement();

    $this->artisan('nvl:mail-notifications:webhooks:remove', [
        '--provider' => 'mailersend',
    ])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.mailersend.test/v1/webhooks/managed%2Fid');
    Http::assertSentCount(2);
});

it('removes every domain webhook only with the explicit all flag', function () {
    Http::fakeSequence()
        ->push(remoteMailerSendWebhookList([
            remoteMailerSendWebhook(['id' => 'managed-id']),
            remoteMailerSendWebhook([
                'id' => 'unmanaged-id',
                'name' => 'Another webhook',
            ]),
        ]))
        ->push([], 204)
        ->push([], 204);
    configureMailerSendWebhookManagement();

    $this->artisan('nvl:mail-notifications:webhooks:remove', [
        '--provider' => 'mailersend',
        '--all' => true,
    ])->assertSuccessful();

    Http::assertSentCount(3);
});

it('dry runs list remote state without writing', function (
    string $command,
    array $options,
) {
    Http::fake([
        '*' => Http::response(remoteMailerSendWebhookList([])),
    ]);
    configureMailerSendWebhookManagement();

    $this->artisan($command, [
        '--provider' => 'mailersend',
        '--dry-run' => true,
        ...$options,
    ])->expectsOutputToContain('dry-run')
        ->assertSuccessful();

    Http::assertSent(
        static fn (Request $request): bool => $request->method() === 'GET',
    );
    Http::assertSentCount(1);
})->with([
    'sync' => ['nvl:mail-notifications:webhooks:sync', []],
    'remove all' => [
        'nvl:mail-notifications:webhooks:remove',
        ['--all' => true],
    ],
]);

it('continues bounded all-removal after a partial API failure', function () {
    Http::fakeSequence()
        ->push(remoteMailerSendWebhookList([
            remoteMailerSendWebhook(['id' => 'first']),
            remoteMailerSendWebhook(['id' => 'second']),
        ]))
        ->push([], 204)
        ->push(['message' => 'response body must remain private'], 500);
    configureMailerSendWebhookManagement();

    $this->artisan('nvl:mail-notifications:webhooks:remove', [
        '--provider' => 'mailersend',
        '--all' => true,
    ])->doesntExpectOutput('response body must remain private')
        ->expectsOutputToContain('failed=1')
        ->assertFailed();

    Http::assertSentCount(3);
});

it('sanitizes API authentication failures', function () {
    Http::fake([
        '*' => Http::response(
            ['message' => 'private provider response'],
            401,
        ),
    ]);
    configureMailerSendWebhookManagement();

    $this->artisan('nvl:mail-notifications:webhooks:sync', [
        '--provider' => 'mailersend',
    ])->doesntExpectOutput('api-token-secret')
        ->doesntExpectOutput('private provider response')
        ->expectsOutputToContain('HTTP status [401]')
        ->assertFailed();
});

it('sanitizes API connection failures', function () {
    Http::fake(['*' => Http::failedConnection()]);
    configureMailerSendWebhookManagement();

    $this->artisan('nvl:mail-notifications:webhooks:sync', [
        '--provider' => 'mailersend',
    ])->doesntExpectOutput('api-token-secret')
        ->expectsOutputToContain('API connection failed')
        ->assertFailed();
});

it('stops listing at the configured pagination bound before mutation', function () {
    $records = [];

    for ($index = 0; $index < 10; $index++) {
        $records[] = remoteMailerSendWebhook([
            'id' => 'webhook-'.$index,
            'name' => 'Other '.$index,
        ]);
    }

    Http::fake([
        '*' => Http::response(remoteMailerSendWebhookList($records, 3)),
    ]);
    configureMailerSendWebhookManagement([
        'pagination.page_size' => 10,
        'pagination.max_pages' => 2,
    ]);

    $this->artisan('nvl:mail-notifications:webhooks:sync', [
        '--provider' => 'mailersend',
    ])->expectsOutputToContain('pagination bound')
        ->assertFailed();

    Http::assertSentCount(2);
});

it('validates enabled management locally through the package doctor', function () {
    configureMailerSendWebhookManagement(['token' => null]);

    $configuration = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration');

    expect($configuration)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('[token]');
    Http::assertNothingSent();
});

it('skips management credentials when remote management is disabled', function () {
    configureMailerSendWebhookManagement([
        'enabled' => false,
        'token' => null,
        'domain_id' => null,
        'webhook.url' => null,
    ]);

    $configuration = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration');

    expect($configuration)
        ->not->toBeNull()
        ->passed->toBeTrue();
    Http::assertNothingSent();
});

it('requires webhook processing and a same-name capable adapter for management', function (
    bool $registerAdapter,
    bool $webhooksEnabled,
    string $message,
) {
    configureMailerSendWebhookManagement(
        registerAdapter: $registerAdapter,
    );
    config()->set('mail-notifications.webhooks.enabled', $webhooksEnabled);

    $configuration = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration');

    expect($configuration)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain($message);
})->with([
    'processing disabled' => [true, false, 'requires package webhook processing'],
    'adapter missing' => [false, true, 'requires a same-name provider adapter'],
]);

it('refuses command I/O when no capable same-name runtime adapter exists', function (
    bool $registerIncapableAdapter,
) {
    Http::preventStrayRequests();
    configureMailerSendWebhookManagement(registerAdapter: false);

    if ($registerIncapableAdapter) {
        app()->instance(
            ProviderRegistry::class,
            new ProviderRegistry([
                new class implements ProviderAdapter
                {
                    public function name(): string
                    {
                        return 'mailersend';
                    }
                },
            ]),
        );
    }

    $this->artisan('nvl:mail-notifications:webhooks:sync', [
        '--provider' => 'mailersend',
    ])->expectsOutputToContain('same-name provider adapter')
        ->assertFailed();

    Http::assertNothingSent();
})->with([
    'missing adapter' => [false],
    'incapable adapter' => [true],
]);

it('rejects non-string provider option input before remote I/O', function () {
    Http::preventStrayRequests();
    configureMailerSendWebhookManagement();

    $this->artisan('nvl:mail-notifications:webhooks:sync', [
        '--provider' => ['mailersend'],
    ])->expectsOutputToContain('non-empty provider name')
        ->assertFailed();

    Http::assertNothingSent();
});

it('rejects unsafe or incompatible management configuration', function (
    string $key,
    mixed $value,
    string $message,
) {
    configureMailerSendWebhookManagement([$key => $value]);

    expect(fn () => app(MailerSendRemoteWebhookManager::class)
        ->validateConfiguration())
        ->toThrow(MailTrackingException::class, $message);
})->with([
    'HTTP callback' => [
        'webhook.url',
        'http://app.example.test/webhook',
        'absolute HTTPS',
    ],
    'callback credentials' => [
        'webhook.url',
        'https://user:pass@app.example.test/webhook',
        'without credentials',
    ],
    'long callback bytes' => [
        'webhook.url',
        'https://example.test/'.str_repeat('é', 90),
        '191',
    ],
    'legacy version' => ['webhook.version', 1, 'version'],
    'unsupported event' => [
        'webhook.events',
        ['activity.sent', 'sender_identity.verified'],
        'unsupported',
    ],
    'unbounded timeout' => ['timeout_seconds', 120, 'between 1 and 60'],
    'unbounded connect timeout' => [
        'connect_timeout_seconds',
        0,
        'between 1 and 60',
    ],
    'connect timeout exceeds total timeout' => [
        'connect_timeout_seconds',
        11,
        'cannot exceed',
    ],
    'unbounded pages' => ['pagination.max_pages', 101, 'between 1 and 100'],
    'HTTP API URL' => [
        'api_url',
        'http://api.mailersend.test/v1',
        'absolute HTTPS',
    ],
]);

it('rejects malformed package switches before MailerSend management', function (
    string $key,
) {
    configureMailerSendWebhookManagement();
    config()->set($key, 'false');

    expect(fn () => app(MailerSendRemoteWebhookManager::class)
        ->validateConfiguration())
        ->toThrow(MailTrackingException::class, 'must be a boolean');
})->with([
    'package switch' => 'mail-notifications.enabled',
    'webhook switch' => 'mail-notifications.webhooks.enabled',
]);
