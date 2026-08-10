<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Nvl\MailNotifications\Contracts\SensitiveDataRedactor;
use Nvl\MailNotifications\Contracts\SensitiveDataTransformer;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Exceptions\MailTrackingException;
use Nvl\MailNotifications\Exceptions\SensitiveStorageException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\Services\DefaultSensitiveDataRedactor;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\Services\MailTheme;
use Nvl\MailNotifications\Services\SensitiveStorageCodec;
use Nvl\MailNotifications\Tests\Fixtures\RotatingSensitiveDataTransformer;
use Nvl\MailNotifications\Tests\Fixtures\TestTrackable;

it('loads package presentation after Laravel configured mail paths', function () {
    $paths = config('mail.markdown.paths');

    expect($paths)->toBeArray()
        ->and($paths)->not->toBeEmpty()
        ->and($paths[0])->toEndWith('tests/Fixtures/views')
        ->and(end($paths))->toEndWith('resources/views/mail');
});

it('shares configured presentation in published-only mode', function () {
    config()->set('mail-notifications.presentation.auto_load', false);
    config()->set('mail-notifications.presentation.tokens.primary', '#123456');
    config()->set('mail-notifications.presentation.brand.name', 'Published Brand');
    config()->set('mail-notifications.tracking.enabled', false);

    (new MailNotificationsServiceProvider(app()))->boot();

    $shared = app(ViewFactory::class)->getShared();

    expect($shared['nvlMailTheme']['primary'])->toBe('#123456')
        ->and($shared['nvlMailBrand']['name'])->toBe('Published Brand');
});

it('updates a Markdown renderer resolved before presentation registration', function () {
    $hostPath = __DIR__.'/../Fixtures/views';
    $packagePath = dirname(__DIR__, 2).'/resources/views/mail';
    config()->set('mail.markdown.paths', [$hostPath]);
    config()->set('mail-notifications.tracking.enabled', false);
    $markdown = app(Markdown::class);
    $markdown->loadComponentsFrom([$hostPath]);

    (new MailNotificationsServiceProvider(app()))->boot();

    expect($markdown->htmlComponentPaths())
        ->toContain($hostPath.'/html')
        ->toContain($packagePath.'/html');
});

it('publishes the tokenized theme to Laravels conventional override path', function () {
    $paths = MailNotificationsServiceProvider::pathsToPublish(
        MailNotificationsServiceProvider::class,
        'mail-notifications-mail-views',
    );

    expect($paths)
        ->toHaveKey(
            dirname(__DIR__, 2).'/resources/views/mail',
            resource_path('views/vendor/mail'),
        );
});

it('registers package migrations for timestamp-aware publishing', function () {
    $paths = MailNotificationsServiceProvider::pathsToPublish(
        MailNotificationsServiceProvider::class,
        'mail-notifications-migrations',
    );
    $migrationPath = realpath(dirname(__DIR__, 2).'/database/migrations');
    $publishableMigrationPaths = array_map(
        static fn (string $path): string|false => realpath($path),
        ServiceProvider::publishableMigrationPaths(),
    );

    expect($paths)->toHaveKey(
        dirname(__DIR__, 2).'/database/migrations',
        database_path('migrations'),
    )->and($publishableMigrationPaths)->toContain($migrationPath);
});

it('exposes serializable defaults for every host integration seam', function () {
    $configuration = config('mail-notifications');

    expect($configuration)->toBeArray()
        ->and(serialize($configuration))->toBeString()
        ->and(config('mail-notifications.extensions'))->toBe([
            'provider_adapters' => [],
            'message_id_resolvers' => [],
            'notifiable_type_providers' => [],
            'scheduled_message_factories' => [],
            'webhook_managers' => [],
        ])
        ->and(config('mail-notifications.notifiable_types'))->toBe([])
        ->and(config('mail-notifications.services.tracking_lifecycle'))
        ->toBe(DatabaseTrackingLifecycle::class)
        ->and(config('mail-notifications.services.sensitive_data_redactor'))
        ->toBe(DefaultSensitiveDataRedactor::class)
        ->and(config('mail-notifications.services.sensitive_storage_transformer'))
        ->toBeNull()
        ->and(config('mail-notifications.privacy.sensitive_storage.enabled'))
        ->toBeFalse()
        ->and(app(TrackingLifecycle::class))
        ->toBeInstanceOf(DatabaseTrackingLifecycle::class)
        ->and(app(SensitiveDataRedactor::class))
        ->toBeInstanceOf(DefaultSensitiveDataRedactor::class)
        ->and(app(SensitiveStorageCodec::class))
        ->toBeInstanceOf(SensitiveStorageCodec::class)
        ->and(app()->bound(SensitiveDataTransformer::class))->toBeFalse()
        ->and(config('mail-notifications.webhooks.enabled'))->toBeTrue()
        ->and(config('mail-notifications.webhooks.max_payload_bytes'))
        ->toBe(1_048_576);
});

it('reports the disabled sensitive-storage default without probing a transformer', function () {
    $check = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'privacy.sensitive_storage');

    expect($check)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->message->toContain(
            'disabled and no transformer is configured',
        );
});

it('rejects invalid configured extension registrations', function (
    string $configKey,
    mixed $configured,
    string $message,
) {
    config()->set($configKey, $configured);

    expect(fn () => (new MailNotificationsServiceProvider(app()))->register())
        ->toThrow(LogicException::class, $message);
})->with([
    'extension list is not an array' => [
        'mail-notifications.extensions.provider_adapters',
        stdClass::class,
        'must be an array',
    ],
    'provider adapter violates its contract' => [
        'mail-notifications.extensions.provider_adapters',
        [stdClass::class],
        'must implement',
    ],
    'message resolver violates its contract' => [
        'mail-notifications.extensions.message_id_resolvers',
        [stdClass::class],
        'must implement',
    ],
    'notifiable provider violates its contract' => [
        'mail-notifications.extensions.notifiable_type_providers',
        [stdClass::class],
        'must implement',
    ],
    'scheduled factory violates its contract' => [
        'mail-notifications.extensions.scheduled_message_factories',
        [stdClass::class],
        'must implement',
    ],
    'webhook manager violates its contract' => [
        'mail-notifications.extensions.webhook_managers',
        [stdClass::class],
        'must implement',
    ],
]);

it('rejects invalid configured service implementations', function (
    string $configKey,
) {
    config()->set($configKey, stdClass::class);

    expect(fn () => (new MailNotificationsServiceProvider(app()))->register())
        ->toThrow(LogicException::class, 'must implement');
})->with([
    'tracking lifecycle' => 'mail-notifications.services.tracking_lifecycle',
    'sensitive data redactor' => 'mail-notifications.services.sensitive_data_redactor',
]);

it('rejects enabled sensitive storage without a valid bounded transformer', function (
    string $key,
    mixed $value,
    string $message,
    ?string $transformer = null,
) {
    if ($transformer !== null) {
        config()->set(
            'mail-notifications.services.sensitive_storage_transformer',
            $transformer,
        );
    }

    config()->set($key, $value);

    expect(fn () => (new MailNotificationsServiceProvider(app()))->register())
        ->toThrow(SensitiveStorageException::class, $message);
})->with([
    'missing transformer' => [
        'mail-notifications.privacy.sensitive_storage.enabled',
        true,
        'requires a transformer class',
    ],
    'invalid transformer' => [
        'mail-notifications.services.sensitive_storage_transformer',
        stdClass::class,
        'must implement',
    ],
    'invalid enabled switch' => [
        'mail-notifications.privacy.sensitive_storage.enabled',
        'yes',
        'enabled must be a boolean',
    ],
    'invalid enabled switch with a configured transformer' => [
        'mail-notifications.privacy.sensitive_storage.enabled',
        'yes',
        'enabled must be a boolean',
        RotatingSensitiveDataTransformer::class,
    ],
    'invalid transformed bound' => [
        'mail-notifications.privacy.sensitive_storage.max_transformed_bytes',
        0,
        'transformed byte limit',
    ],
]);

it('rejects invalid direct notifiable type configuration', function () {
    config()->set('mail-notifications.notifiable_types', TestTrackable::class);
    app()->forgetInstance(MailNotificationNotifiableTypeRegistry::class);

    expect(fn () => app(MailNotificationNotifiableTypeRegistry::class))
        ->toThrow(LogicException::class, 'must be an array');
});

it('honors configured storage connection and table names', function () {
    config()->set('mail-notifications.storage.connection', 'mail-audit');
    config()->set(
        'mail-notifications.storage.tables.notifications',
        'outbound_mail',
    );
    config()->set(
        'mail-notifications.storage.tables.events',
        'outbound_mail_events',
    );
    $notification = new MailNotification;
    $event = new MailNotificationEvent;

    expect($notification->getConnectionName())->toBe('mail-audit')
        ->and($notification->getTable())->toBe('outbound_mail')
        ->and($event->getConnectionName())->toBe('mail-audit')
        ->and($event->getTable())->toBe('outbound_mail_events');
});

it('uses unbounded text storage for sender display names', function () {
    $notification = new MailNotification;
    $schema = Schema::connection($notification->getConnectionName());

    expect($schema->getColumnType($notification->getTable(), 'from_name'))
        ->toBe('text');
});

it('renders the tokenized Markdown theme with configurable brand values', function () {
    config()->set('mail-notifications.presentation.tokens.primary', '#123456');
    config()->set('mail-notifications.presentation.brand', [
        'name' => 'Acme Mail',
        'url' => 'https://acme.example.test',
        'logo_url' => null,
        'logo_alt' => null,
        'support_text' => 'Contact the Acme support team.',
        'footer_text' => 'Acme transactional email.',
    ]);
    $views = app(ViewFactory::class);
    $theme = app(MailTheme::class);
    $views->share('nvlMailTheme', $theme->tokens());
    $views->share('nvlMailBrand', $theme->brand());

    $html = app(Markdown::class)
        ->render('mail-notifications-tests::message', [
            'rows' => [
                [
                    'label' => 'Reference',
                    'value' => 'MAIL-123',
                ],
            ],
        ])
        ->toHtml();

    expect($html)
        ->toContain('Acme Mail')
        ->toContain('#123456')
        ->toContain('Tracked delivery')
        ->toContain('Provider-neutral mail remains compatible')
        ->toContain('Delivery details remain application-owned')
        ->toContain('MAIL-123')
        ->toContain('Contact the Acme support team.');
});

it('keeps default presentation free from package-owned copy', function () {
    $html = app(Markdown::class)
        ->render('mail-notifications-tests::message')
        ->toHtml();
    $text = app(Markdown::class)
        ->renderText('mail-notifications-tests::message')
        ->toHtml();

    expect($html)
        ->not->toContain('All rights reserved')
        ->not->toContain('If you need help')
        ->and($text)
        ->not->toContain('All rights reserved')
        ->not->toContain('If you need help')
        ->not->toContain('[WARNING]');
});

it('falls back from invalid token and brand configuration safely', function () {
    config()->set('mail-notifications.presentation.tokens.primary', 'red; display:none');
    config()->set('mail-notifications.presentation.tokens.content_width', 'calc(100% + 1px)');
    config()->set('mail-notifications.presentation.brand.logo_url', 'javascript:alert(1)');
    config()->set('mail-notifications.presentation.brand.header_enabled', 'false');

    $theme = app(MailTheme::class);

    expect($theme->tokens()['primary'])->toBe('#2563eb')
        ->and($theme->tokens()['content_width'])->toBe('600px')
        ->and($theme->brand()['logo_url'])->toBeNull()
        ->and($theme->brand()['header_enabled'])->toBeTrue()
        ->and($theme->brand()['footer_enabled'])->toBeTrue();
});

it('escapes data table values unless trusted HTML is explicit', function () {
    $html = app(Markdown::class)
        ->render('mail-notifications-tests::message', [
            'rows' => [
                [
                    'label' => 'Escaped',
                    'value' => '<strong>Not trusted</strong>',
                ],
                [
                    'label' => 'Trusted',
                    'value' => '<strong>Trusted markup</strong>',
                    'html' => true,
                ],
                [
                    'label' => 'Truthy string',
                    'value' => '<em>Still not trusted</em>',
                    'html' => 'true',
                ],
            ],
        ])
        ->toHtml();

    expect($html)
        ->toContain('&lt;strong&gt;Not trusted&lt;/strong&gt;')
        ->not->toContain('<strong>Not trusted</strong>')
        ->toContain('<strong style=')
        ->toContain('Trusted markup</strong>')
        ->toContain('&lt;em&gt;Still not trusted&lt;/em&gt;')
        ->not->toContain('<em>Still not trusted</em>');
});

it('normalizes public component variants before using them in markup', function () {
    $html = app(Markdown::class)
        ->render('mail-notifications-tests::message', [
            'alertType' => 'warning" onclick="alert(1)',
            'buttonColor' => 'primary" onclick="alert(1)',
            'buttonAlign' => 'right" onclick="alert(1)',
        ])
        ->toHtml();

    expect($html)
        ->toContain('class="alert alert-info"')
        ->toContain('class="button button-primary"')
        ->not->toContain('onclick=');
});

it('renders generic plain text and allows brand chrome to be disabled', function () {
    config()->set('mail-notifications.presentation.brand', [
        'header_enabled' => false,
        'footer_enabled' => false,
        'name' => 'Hidden Brand',
        'url' => 'https://hidden.example.test',
        'logo_url' => null,
        'logo_alt' => null,
        'support_text' => 'Reply for assistance.',
        'footer_text' => 'This footer must not render.',
    ]);
    $views = app(ViewFactory::class);
    $theme = app(MailTheme::class);
    $views->share('nvlMailTheme', $theme->tokens());
    $views->share('nvlMailBrand', $theme->brand());

    $text = app(Markdown::class)
        ->renderText('mail-notifications-tests::message')
        ->toHtml();

    expect($text)
        ->toContain('Tracked delivery')
        ->toContain('Reply for assistance.')
        ->not->toContain('Hidden Brand')
        ->not->toContain('This footer must not render.');
});

it('reports healthy package configuration and schema', function () {
    $this->artisan('nvl:mail-notifications:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertSuccessful()
        ->expectsOutputToContain('"healthy": true');
});

it('warns when automatic migrations overlap a published host copy', function () {
    $published = database_path(
        'migrations/2099_01_01_000000_create_mail_notification_tables.php',
    );
    file_put_contents($published, "<?php\n");

    try {
        $check = collect(app(MailNotificationsDoctor::class)->inspect())
            ->firstWhere('key', 'schema.migration_ownership');

        expect($check)
            ->not->toBeNull()
            ->passed->toBeFalse()
            ->severity->toBe('warning')
            ->message->toContain('create_mail_notification_tables');

        $this->artisan('nvl:mail-notifications:doctor', ['--format' => 'json'])
            ->assertSuccessful();
        $this->artisan('nvl:mail-notifications:doctor', [
            '--strict' => true,
            '--format' => 'json',
        ])->assertFailed();
    } finally {
        unlink($published);
    }
});

it('reports exact package migration ownership as healthy', function () {
    $history = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.migrations');

    expect($history)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->severity->toBe('error')
        ->message->toContain('exact repository ownership record');
});

it('allows a missing read-only compatibility preflight to run again', function () {
    $migrationName =
        '2026_07_28_000000_assert_mail_notification_schema_compatibility';
    app(Migrator::class)->getRepository()->delete((object) [
        'migration' => $migrationName,
    ]);

    $history = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.migrations');
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2)
        .'/database/migrations/'.$migrationName.'.php';

    expect($history)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->severity->toBe('error')
        ->message->toContain($migrationName)
        ->toContain('Only read-only package compatibility preflight history')
        ->and(static fn () => $migration->up())
        ->not->toThrow(Throwable::class);
});

it('detects no-op rollback history before migration ownership is refused', function () {
    $migrationName =
        '2026_07_30_000100_create_scheduled_mail_messages_table';
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2)
        .'/database/migrations/'.$migrationName.'.php';
    $migration->down();
    app(Migrator::class)->getRepository()->delete((object) [
        'migration' => $migrationName,
    ]);

    $checks = collect(app(MailNotificationsDoctor::class)->inspect());
    $history = $checks->firstWhere('key', 'schema.migrations');
    $schemaColumns = $checks->firstWhere('key', 'schema.columns');

    expect($history)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->severity->toBe('error')
        ->message->toContain($migrationName)
        ->toContain(
            'Retained tables owned by missing creator record(s)',
        )
        ->and($schemaColumns)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->and(Schema::hasTable('scheduled_mail_messages'))
        ->toBeTrue()
        ->and(static fn () => $migration->up())
        ->toThrow(
            LogicException::class,
            'cannot be adopted because the package migration',
        );

    $this->artisan('nvl:mail-notifications:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertFailed()
        ->expectsOutputToContain('schema.migrations');
});

it('allows a missing creator to complete when only its owned table is absent', function () {
    $migrationName =
        '2026_07_30_000100_create_scheduled_mail_messages_table';
    app(Migrator::class)->getRepository()->delete((object) [
        'migration' => $migrationName,
    ]);
    Schema::drop('scheduled_mail_messages');

    $history = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.migrations');
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2)
        .'/database/migrations/'.$migrationName.'.php';

    expect($history)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->severity->toBe('error')
        ->message->toContain($migrationName)
        ->toContain('own no existing configured tables')
        ->toContain('run the pending package migrations')
        ->and(Schema::hasTable('mail_notifications'))->toBeTrue()
        ->and(Schema::hasTable('mail_notification_events'))->toBeTrue()
        ->and(Schema::hasTable('scheduled_mail_messages'))->toBeFalse()
        ->and(static fn () => $migration->up())
        ->not->toThrow(Throwable::class)
        ->and(Schema::hasTable('scheduled_mail_messages'))->toBeTrue();
});

it('accepts host-owned migration history when package migrations are disabled', function () {
    $migrationName =
        '2026_07_30_000100_create_scheduled_mail_messages_table';
    app(Migrator::class)->getRepository()->delete((object) [
        'migration' => $migrationName,
    ]);
    config()->set('mail-notifications.migrations.enabled', false);

    $history = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.migrations');

    expect($history)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->severity->toBe('error')
        ->message->toContain('host application owns migration history');

    $this->artisan('nvl:mail-notifications:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertSuccessful()
        ->expectsOutputToContain('"healthy": true');
});

it('recommends pending migrations only for a genuinely fresh package schema', function () {
    $migrator = app(Migrator::class);

    foreach (array_keys($migrator->getMigrationFiles(
        dirname(__DIR__, 2).'/database/migrations',
    )) as $migrationName) {
        $migrator->getRepository()->delete((object) [
            'migration' => $migrationName,
        ]);
    }

    Schema::drop('mail_notification_events');
    Schema::drop('mail_notifications');
    Schema::drop('scheduled_mail_messages');

    $history = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.migrations');

    expect($history)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->severity->toBe('error')
        ->message->toContain(
            'No package tables or creator ownership records are present',
        )
        ->toContain('run the pending package migrations');
});

it('rejects malformed package migration ownership switches', function (
    mixed $enabled,
) {
    config()->set('mail-notifications.migrations.enabled', $enabled);

    $history = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.migrations');

    expect($history)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->severity->toBe('error')
        ->message->toContain('must be an actual boolean');
})->with([
    'string false' => 'false',
    'integer false' => 0,
    'null' => null,
]);

it('rejects malformed boot-time feature switches', function (
    string $key,
    string $exception,
) {
    config()->set($key, 'false');

    expect(fn () => (new MailNotificationsServiceProvider(app()))->boot())
        ->toThrow($exception, 'boolean');
})->with([
    'package switch' => [
        'mail-notifications.enabled',
        LogicException::class,
    ],
    'tracking switch' => [
        'mail-notifications.tracking.enabled',
        MailTrackingException::class,
    ],
    'presentation switch' => [
        'mail-notifications.presentation.enabled',
        LogicException::class,
    ],
    'presentation auto-load switch' => [
        'mail-notifications.presentation.auto_load',
        LogicException::class,
    ],
    'migration switch' => [
        'mail-notifications.migrations.enabled',
        LogicException::class,
    ],
]);

it('reports malformed runtime feature switches as unhealthy', function (
    string $key,
) {
    config()->set($key, 'false');

    $configuration = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration');

    expect($configuration)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('must be a boolean');
})->with([
    'package switch' => 'mail-notifications.enabled',
    'tracking switch' => 'mail-notifications.tracking.enabled',
    'webhook switch' => 'mail-notifications.webhooks.enabled',
]);

it('reports production-safe testing interception configuration', function () {
    config()->set('mail.testing', [
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => true,
        'environments' => ['local', 'testing', 'staging'],
    ]);

    $testing = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration.testing');

    expect($testing)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->message->toContain('non-production');
});

it('reports inactive and package-fallback testing interception modes', function () {
    config()->set('mail-notifications.enabled', false);
    $inactive = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration.testing');

    expect($inactive)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->message->toContain('package is disabled');

    config()->set('mail-notifications.enabled', true);
    config()->set('mail.testing', []);
    config()->set('mail-notifications.testing', [
        0 => 'ignored',
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => true,
        'environments' => [' testing ', null, ''],
    ]);
    $fallback = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration.testing');

    expect($fallback)
        ->not->toBeNull()
        ->passed->toBeTrue()
        ->message->toContain('1 non-production environment');
});

it('reports unsafe or unusable testing interception configuration', function (
    array $testingConfiguration,
    string $expectedMessage,
    string $expectedSeverity,
) {
    config()->set('mail.testing', $testingConfiguration);

    $testing = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration.testing');

    expect($testing)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->severity->toBe($expectedSeverity)
        ->message->toContain($expectedMessage);
})->with([
    'invalid recipient' => [[
        'enabled' => true,
        'to_address' => 'not-an-email',
        'respect_environment' => true,
        'environments' => ['testing'],
    ], 'valid recipient address', 'error'],
    'missing recipient' => [[
        'enabled' => true,
        'respect_environment' => true,
        'environments' => ['testing'],
    ], 'valid recipient address', 'error'],
    'malformed environment allowlist' => [[
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => true,
        'environments' => 'testing',
    ], 'environments must be an array', 'error'],
    'empty allowlist' => [[
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => true,
        'environments' => [],
    ], 'non-empty environment allowlist', 'error'],
    'unrestricted interception' => [[
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => false,
    ], 'can run in production', 'warning'],
    'production allowlist' => [[
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => true,
        'environments' => ['production'],
    ], 'can run in production', 'warning'],
    'malformed enabled switch' => [[
        'enabled' => 'false',
    ], 'must be a boolean', 'error'],
    'malformed environment switch' => [[
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => 'false',
        'environments' => ['testing'],
    ], 'must be a boolean', 'error'],
]);

it('treats unsafe testing interception as a strict doctor failure', function () {
    config()->set('mail.testing', [
        'enabled' => true,
        'to_address' => 'preview@example.test',
        'respect_environment' => false,
    ]);

    $this->artisan('nvl:mail-notifications:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertFailed()
        ->expectsOutputToContain('configuration.testing');
});

it('rejects unsupported doctor output formats', function () {
    $this->artisan('nvl:mail-notifications:doctor', [
        '--format' => 'yaml',
    ])->expectsOutputToContain('The --format option must be text or json.')
        ->assertExitCode(Command::INVALID);
});

it('reports invalid package configuration as unhealthy', function () {
    config()->set('mail-notifications.tracking.failure_policy', 'continue_anyway');

    $configuration = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration');

    expect($configuration)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('fail_open')
        ->toContain('fail_closed');

    $this->artisan('nvl:mail-notifications:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertFailed();
});

it('reports invalid provider identity configuration as unhealthy', function () {
    config()->set('mail-notifications.providers.mailers', 'smtp');

    $configuration = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'configuration');

    expect($configuration)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('provider mailer mappings must be an array');
});

it('reports incomplete package schema as unhealthy', function () {
    Schema::drop((new MailNotificationEvent)->getTable());

    $schema = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.events');

    expect($schema)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('mail_notification_events');

    $this->artisan('nvl:mail-notifications:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertFailed();
});

it('reports an unavailable storage connection instead of throwing', function () {
    config()->set(
        'mail-notifications.storage.connection',
        'missing-mail-notifications-connection',
    );

    $schema = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.connection');

    expect($schema)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->severity->toBe('error')
        ->message->toContain('configured mail tracking database is unavailable');
});

it('reports missing lifecycle columns as unhealthy', function () {
    $table = (new MailNotification)->getTable();

    Schema::table($table, function (Blueprint $table): void {
        $table->dropColumn('provider_occurred_at');
    });

    $schema = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.columns');

    expect($schema)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('provider_occurred_at');
});

it('reports incompatible lifecycle column definitions as unhealthy', function () {
    $table = (new MailNotification)->getTable();

    Schema::table($table, function (Blueprint $table): void {
        $table->text('mailer')->change();
        $table->string('status', 32)->default('queued')->change();
    });

    $schema = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.definitions');

    expect($schema)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('mail_notifications.mailer type')
        ->toContain('mail_notifications.status default');
});

it('reports missing idempotency constraints as unhealthy', function () {
    $table = (new MailNotificationEvent)->getTable();

    Schema::table($table, function (Blueprint $table): void {
        $table->dropUnique('mail_notification_events_provider_event_unique');
    });

    $schema = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.constraints');

    expect($schema)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('provider event identity');
});

it('reports a missing notification retention index as unhealthy', function () {
    $table = (new MailNotification)->getTable();

    Schema::table($table, function (Blueprint $table): void {
        $table->dropIndex('mail_notifications_status_changed_index');
    });

    $schema = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.indexes');

    expect($schema)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->severity->toBe('warning')
        ->message->toContain('status-change retention lookup');
});

it('reports a provider event foreign key targeting the wrong owner table', function () {
    $eventTable = (new MailNotificationEvent)->getTable();
    $wrongOwnerTable = 'wrong_mail_notification_owners';

    Schema::drop($eventTable);
    Schema::create($wrongOwnerTable, function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
    Schema::create($eventTable, function (Blueprint $table) use ($wrongOwnerTable): void {
        $table->uuid('id')->primary();
        $table->foreignUuid('mail_notification_id')
            ->constrained($wrongOwnerTable)
            ->cascadeOnDelete();
        $table->string('provider', 128);
        $table->string('provider_event_id', 255);
        $table->string('provider_message_id', 255)->nullable();
        $table->string('normalized_type', 32);
        $table->timestampTz('occurred_at', 6);
        $table->json('metadata')->nullable();
        $table->timestampTz('processed_at', 6);
        $table->timestampsTz(6);
        $table->unique(
            ['provider', 'provider_event_id'],
            'mail_notification_events_provider_event_unique',
        );
        $table->index(
            ['mail_notification_id', 'occurred_at'],
            'mail_notification_events_notification_time_index',
        );
    });

    $schema = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.constraints');

    expect($schema)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('provider event ownership cascade');
});
