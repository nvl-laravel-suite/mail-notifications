<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\Support\StatusConstraintInspector;

const MAIL_NOTIFICATION_GUARD_CONNECTION = 'mail-notification-guard-test';
const MAIL_NOTIFICATION_GUARD_NOTIFICATIONS = 'guarded_mail_notifications';
const MAIL_NOTIFICATION_GUARD_EVENTS = 'guarded_mail_notification_events';
const MAIL_NOTIFICATION_GUARD_SCHEDULED = 'guarded_scheduled_mail_messages';
const MAIL_NOTIFICATION_GUARD_DRIFTED_NOTIFICATIONS =
    'drifted_mail_notifications';
const MAIL_NOTIFICATION_COMPATIBILITY_PREFLIGHT =
    '2026_07_28_000000_assert_mail_notification_schema_compatibility';
const MAIL_NOTIFICATION_SCHEMA_CREATOR =
    '2026_07_29_000000_create_mail_notification_tables';
const MAIL_NOTIFICATION_SCHEDULED_CREATOR =
    '2026_07_30_000100_create_scheduled_mail_messages_table';

/**
 * Load a fresh compatibility preflight migration instance.
 */
function mailNotificationCompatibilityGuard(): Migration
{
    return require dirname(__DIR__, 2)
        .'/database/migrations/2026_07_28_000000_assert_mail_notification_schema_compatibility.php';
}

/**
 * Load a fresh package schema migration instance.
 */
function mailNotificationSchemaCreator(): Migration
{
    return require dirname(__DIR__, 2)
        .'/database/migrations/2026_07_29_000000_create_mail_notification_tables.php';
}

/**
 * Load a fresh scheduled-mail schema migration.
 */
function mailNotificationScheduledSchemaCreator(): Migration
{
    return require dirname(__DIR__, 2)
        .'/database/migrations/2026_07_30_000100_create_scheduled_mail_messages_table.php';
}

/**
 * Configure an isolated SQLite connection and custom package table names.
 */
function configureMailNotificationGuardStorage(string $prefix = ''): Builder
{
    config()->set(
        'database.connections.'.MAIL_NOTIFICATION_GUARD_CONNECTION,
        [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => $prefix,
            'foreign_key_constraints' => true,
        ],
    );
    DB::purge(MAIL_NOTIFICATION_GUARD_CONNECTION);
    config()->set(
        'mail-notifications.storage.connection',
        MAIL_NOTIFICATION_GUARD_CONNECTION,
    );
    config()->set(
        'mail-notifications.storage.tables.notifications',
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
    );
    config()->set(
        'mail-notifications.storage.tables.events',
        MAIL_NOTIFICATION_GUARD_EVENTS,
    );
    config()->set(
        'mail-notifications.storage.tables.scheduled_messages',
        MAIL_NOTIFICATION_GUARD_SCHEDULED,
    );

    return Schema::connection(MAIL_NOTIFICATION_GUARD_CONNECTION);
}

/**
 * Remove the creator record to simulate a fresh or ambiguous host.
 */
function forgetMailNotificationSchemaCreator(): void
{
    $repository = app(Migrator::class)->getRepository();

    if (in_array(
        MAIL_NOTIFICATION_SCHEMA_CREATOR,
        $repository->getRan(),
        true,
    )) {
        $repository->delete((object) [
            'migration' => MAIL_NOTIFICATION_SCHEMA_CREATOR,
        ]);
    }
}

/**
 * Record the exact package creator to establish package table ownership.
 */
function recordMailNotificationSchemaCreator(): void
{
    $repository = app(Migrator::class)->getRepository();

    if (! in_array(
        MAIL_NOTIFICATION_SCHEMA_CREATOR,
        $repository->getRan(),
        true,
    )) {
        $repository->log(MAIL_NOTIFICATION_SCHEMA_CREATOR, 1);
    }
}

/**
 * Record one manually executed package migration in the test repository.
 */
function recordMailNotificationMigration(string $migration): void
{
    $repository = app(Migrator::class)->getRepository();

    if (! in_array($migration, $repository->getRan(), true)) {
        $repository->log($migration, 1);
    }
}

/**
 * Build and record the complete creator-owned schema.
 */
function prepareMailNotificationCreatorSchema(): Builder
{
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    recordMailNotificationSchemaCreator();
    mailNotificationScheduledSchemaCreator()->up();
    recordMailNotificationMigration(MAIL_NOTIFICATION_SCHEDULED_CREATOR);

    return $schema;
}

/**
 * Recreate a complete event table with optional notification ownership.
 */
function recreateMailNotificationGuardEventTable(
    Builder $schema,
    bool $withOwnershipForeignKey,
): void {
    $schema->drop(MAIL_NOTIFICATION_GUARD_EVENTS);
    $schema->create(
        MAIL_NOTIFICATION_GUARD_EVENTS,
        function (Blueprint $table) use ($withOwnershipForeignKey): void {
            $table->uuid('id')->primary();

            if ($withOwnershipForeignKey) {
                $table->foreignUuid('mail_notification_id')
                    ->constrained(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS)
                    ->cascadeOnDelete();
            } else {
                $table->uuid('mail_notification_id');
            }

            $table->string('provider', 128);
            $table->string('provider_event_id', 255);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('normalized_type', 32);
            $table->timestampTz('occurred_at', 6);
            $table->json('metadata')->nullable();
            $table->timestampTz('processed_at', 6);
            $table->timestampTz('redacted_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(
                ['provider', 'provider_event_id'],
                'mail_notification_events_provider_event_unique',
            );
            $table->index(
                ['mail_notification_id', 'occurred_at'],
                'mail_notification_events_notification_time_index',
            );
            $table->index(
                ['redacted_at', 'occurred_at', 'id'],
                'mail_notification_events_redacted_time_index',
            );
        },
    );

    $connection = $schema->getConnection();
    $grammar = $connection->getQueryGrammar();
    $constraint = 'mail_notification_events_normalized_type_check';
    $allowedValues = implode(', ', array_map(
        static fn (MailDeliveryStatus $status): string => "'{$status->value}'",
        MailDeliveryStatus::cases(),
    ));
    $error = "'Status invariant [{$constraint}] rejected an invalid value.'";
    $connection->statement(sprintf(
        'create trigger %s before insert on %s for each row when new.%s not in (%s) begin select raise(abort, %s); end',
        $grammar->wrap($constraint.'_insert'),
        $grammar->wrapTable(MAIL_NOTIFICATION_GUARD_EVENTS),
        $grammar->wrap('normalized_type'),
        $allowedValues,
        $error,
    ));
    $connection->statement(sprintf(
        'create trigger %s before update of %s on %s for each row when new.%s not in (%s) begin select raise(abort, %s); end',
        $grammar->wrap($constraint.'_update'),
        $grammar->wrap('normalized_type'),
        $grammar->wrapTable(MAIL_NOTIFICATION_GUARD_EVENTS),
        $grammar->wrap('normalized_type'),
        $allowedValues,
        $error,
    ));
}

afterEach(function (): void {
    DB::purge(MAIL_NOTIFICATION_GUARD_CONNECTION);
});

it('ships only a compatibility preflight and consolidated creator migrations', function () {
    $files = app(Migrator::class)->getMigrationFiles(
        dirname(__DIR__, 2).'/database/migrations',
    );
    $migrationNames = array_keys($files);

    expect($migrationNames)->toBe([
        MAIL_NOTIFICATION_COMPATIBILITY_PREFLIGHT,
        MAIL_NOTIFICATION_SCHEMA_CREATOR,
        MAIL_NOTIFICATION_SCHEDULED_CREATOR,
    ]);
});

it('keeps the compatibility preflight aligned with the complete runtime schema', function () {
    $guard = new ReflectionClass(mailNotificationCompatibilityGuard());
    $doctor = new ReflectionClass(MailNotificationsDoctor::class);

    expect($guard->getConstant('NOTIFICATION_COLUMNS'))
        ->toBe($doctor->getConstant('NOTIFICATION_COLUMNS'))
        ->and($guard->getConstant('NOTIFICATION_COLUMN_TYPES'))
        ->toBe($doctor->getConstant('NOTIFICATION_COLUMN_TYPES'))
        ->and($guard->getConstant('NULLABLE_NOTIFICATION_COLUMNS'))
        ->toBe($doctor->getConstant('NULLABLE_NOTIFICATION_COLUMNS'))
        ->and($guard->getConstant('NOTIFICATION_COLUMN_LENGTHS'))
        ->toBe($doctor->getConstant('NOTIFICATION_COLUMN_LENGTHS'))
        ->and($guard->getConstant('EVENT_COLUMNS'))
        ->toBe($doctor->getConstant('EVENT_COLUMNS'))
        ->and($guard->getConstant('EVENT_COLUMN_TYPES'))
        ->toBe($doctor->getConstant('EVENT_COLUMN_TYPES'))
        ->and($guard->getConstant('NULLABLE_EVENT_COLUMNS'))
        ->toBe($doctor->getConstant('NULLABLE_EVENT_COLUMNS'))
        ->and($guard->getConstant('EVENT_COLUMN_LENGTHS'))
        ->toBe($doctor->getConstant('EVENT_COLUMN_LENGTHS'));
});

it('creates durable queued-failure identity in the tracking creator', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    recordMailNotificationSchemaCreator();

    mailNotificationCompatibilityGuard()->up();

    expect($schema->hasColumn(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        'queue_reference',
    ))->toBeTrue()
        ->and($schema->hasIndex(
            MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
            'mail_notifications_queue_created_index',
        ))->toBeTrue();
});

it('fails closed on partial tracking tables left after the recorded preflight', function (
    string $existingTable,
    string $missingTable,
    string $statusColumn,
    string $constraint,
) {
    $schema = configureMailNotificationGuardStorage();
    forgetMailNotificationSchemaCreator();
    mailNotificationCompatibilityGuard()->up();
    recordMailNotificationMigration(MAIL_NOTIFICATION_COMPATIBILITY_PREFLIGHT);
    $schema->create(
        $existingTable,
        static function (Blueprint $table) use ($statusColumn): void {
            $table->uuid('id')->primary();
            $table->string($statusColumn, 32)->default('pending');
        },
    );
    $allowedStatuses = array_map(
        static fn (MailDeliveryStatus $status): string => $status->value,
        MailDeliveryStatus::cases(),
    );
    $repository = app(Migrator::class)->getRepository();

    expect($repository->getRan())
        ->toContain(MAIL_NOTIFICATION_COMPATIBILITY_PREFLIGHT)
        ->not->toContain(MAIL_NOTIFICATION_SCHEMA_CREATOR)
        ->and(StatusConstraintInspector::matches(
            connection: $schema->getConnection(),
            table: $existingTable,
            column: $statusColumn,
            constraint: $constraint,
            allowedValues: $allowedStatuses,
        ))->toBeFalse()
        ->and(static fn () => mailNotificationSchemaCreator()->up())
        ->toThrow(
            LogicException::class,
            'is pending, but configured tracking table(s) already exist',
        )
        ->and($schema->hasTable($existingTable))->toBeTrue()
        ->and($schema->hasTable($missingTable))->toBeFalse()
        ->and(StatusConstraintInspector::matches(
            connection: $schema->getConnection(),
            table: $existingTable,
            column: $statusColumn,
            constraint: $constraint,
            allowedValues: $allowedStatuses,
        ))->toBeFalse();
})->with([
    'interrupted after notification table creation' => [
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        MAIL_NOTIFICATION_GUARD_EVENTS,
        'status',
        'mail_notifications_status_check',
    ],
    'retained event table without notification owner' => [
        MAIL_NOTIFICATION_GUARD_EVENTS,
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        'normalized_type',
        'mail_notification_events_normalized_type_check',
    ],
]);

it('fails closed when creator history is removed but preflight history remains', function () {
    $schema = configureMailNotificationGuardStorage();
    forgetMailNotificationSchemaCreator();
    mailNotificationCompatibilityGuard()->up();
    recordMailNotificationMigration(MAIL_NOTIFICATION_COMPATIBILITY_PREFLIGHT);
    mailNotificationSchemaCreator()->up();
    recordMailNotificationSchemaCreator();
    forgetMailNotificationSchemaCreator();
    $repository = app(Migrator::class)->getRepository();
    $notificationColumns = $schema->getColumns(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
    );
    $eventColumns = $schema->getColumns(MAIL_NOTIFICATION_GUARD_EVENTS);

    expect($repository->getRan())
        ->toContain(MAIL_NOTIFICATION_COMPATIBILITY_PREFLIGHT)
        ->not->toContain(MAIL_NOTIFICATION_SCHEMA_CREATOR)
        ->and(static fn () => mailNotificationSchemaCreator()->up())
        ->toThrow(
            LogicException::class,
            'is pending, but configured tracking table(s) already exist',
        )
        ->and($schema->getColumns(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS))
        ->toBe($notificationColumns)
        ->and($schema->getColumns(MAIL_NOTIFICATION_GUARD_EVENTS))
        ->toBe($eventColumns);
});

it('creates exact privacy markers idempotently in both schema creators', function () {
    $schema = prepareMailNotificationCreatorSchema();

    mailNotificationSchemaCreator()->up();
    mailNotificationScheduledSchemaCreator()->up();
    mailNotificationCompatibilityGuard()->up();

    expect($schema->hasColumn(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        'redacted_at',
    ))->toBeTrue()
        ->and($schema->hasColumn(
            MAIL_NOTIFICATION_GUARD_EVENTS,
            'redacted_at',
        ))->toBeTrue()
        ->and($schema->hasColumn(
            MAIL_NOTIFICATION_GUARD_SCHEDULED,
            'redacted_at',
        ))->toBeTrue()
        ->and($schema->hasIndex(
            MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
            'mail_notifications_redacted_time_index',
        ))->toBeTrue()
        ->and($schema->hasIndex(
            MAIL_NOTIFICATION_GUARD_EVENTS,
            'mail_notification_events_redacted_time_index',
        ))->toBeTrue()
        ->and($schema->hasIndex(
            MAIL_NOTIFICATION_GUARD_SCHEDULED,
            'scheduled_mail_messages_redacted_time_index',
        ))->toBeTrue();
});

it('rejects a forged privacy marker column definition', function () {
    $schema = prepareMailNotificationCreatorSchema();
    $schema->table(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        static function (Blueprint $table): void {
            $table->string('redacted_at')->nullable()->change();
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            MAIL_NOTIFICATION_GUARD_NOTIFICATIONS.'.redacted_at type',
        )
        ->and($schema->hasColumn(
            MAIL_NOTIFICATION_GUARD_EVENTS,
            'redacted_at',
        ))->toBeTrue();
});

it('revalidates exact creator-owned indexes before accepting existing storage', function () {
    $schema = prepareMailNotificationCreatorSchema();
    $schema->table(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        static function (Blueprint $table): void {
            $table->dropIndex(
                'mail_notifications_recipient_created_index',
            );
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            'recipient timeline lookup',
        )
        ->and($schema->hasColumn(
            MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
            'redacted_at',
        ))->toBeTrue()
        ->and($schema->hasColumn(
            MAIL_NOTIFICATION_GUARD_EVENTS,
            'redacted_at',
        ))->toBeTrue()
        ->and($schema->hasColumn(
            MAIL_NOTIFICATION_GUARD_SCHEDULED,
            'redacted_at',
        ))->toBeTrue();
});

it('rejects forged privacy markers on every creator-owned table', function (
    string $table,
) {
    $schema = prepareMailNotificationCreatorSchema();
    $schema->table(
        $table,
        static function (Blueprint $table): void {
            $table->string('redacted_at')->nullable()->change();
        },
    );

    $assertCompatibility = $table === MAIL_NOTIFICATION_GUARD_SCHEDULED
        ? static fn () => mailNotificationScheduledSchemaCreator()->up()
        : static fn () => mailNotificationCompatibilityGuard()->up();

    expect($assertCompatibility)
        ->toThrow(
            LogicException::class,
            'redacted_at',
        )
        ->and($schema->hasColumn(
            MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
            'redacted_at',
        ))->toBeTrue();
})->with([
    'event marker' => MAIL_NOTIFICATION_GUARD_EVENTS,
    'scheduled marker' => MAIL_NOTIFICATION_GUARD_SCHEDULED,
]);

it('rejects a forged named privacy marker index', function () {
    $schema = prepareMailNotificationCreatorSchema();
    $schema->table(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        static function (Blueprint $table): void {
            $table->dropIndex('mail_notifications_redacted_time_index');
            $table->index(
                ['redacted_at', 'id'],
                'mail_notifications_redacted_time_index',
            );
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            'privacy retention lookup',
        )
        ->and($schema->hasColumn(
            MAIL_NOTIFICATION_GUARD_EVENTS,
            'redacted_at',
        ))->toBeTrue();
});

it('requires exact creator history before accepting a complete table pair', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    forgetMailNotificationSchemaCreator();

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            'have ambiguous ownership because package schema creator',
        )
        ->and($schema->hasColumn(
            MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
            'queue_reference',
        ))->toBeTrue();
});

it('leaves a drifted configured table untouched on preflight and rollback', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    recordMailNotificationSchemaCreator();
    $schema->create(
        MAIL_NOTIFICATION_GUARD_DRIFTED_NOTIFICATIONS,
        static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('correlation_id')->unique();
        },
    );
    config()->set(
        'mail-notifications.storage.tables.notifications',
        MAIL_NOTIFICATION_GUARD_DRIFTED_NOTIFICATIONS,
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            'missing columns',
        )
        ->and($schema->hasColumn(
            MAIL_NOTIFICATION_GUARD_DRIFTED_NOTIFICATIONS,
            'queue_reference',
        ))->toBeFalse();

    $schema->table(
        MAIL_NOTIFICATION_GUARD_DRIFTED_NOTIFICATIONS,
        static function (Blueprint $table): void {
            $table->uuid('queue_reference')->nullable();
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->down())
        ->not->toThrow(Throwable::class)
        ->and($schema->hasColumn(
            MAIL_NOTIFICATION_GUARD_DRIFTED_NOTIFICATIONS,
            'queue_reference',
        ))->toBeTrue();
});

it('keeps package mail tables columns and indexes on migration rollback', function () {
    $schema = prepareMailNotificationCreatorSchema();
    $notificationColumns = $schema->getColumns(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
    );
    $notificationIndexes = $schema->getIndexes(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
    );
    $eventColumns = $schema->getColumns(MAIL_NOTIFICATION_GUARD_EVENTS);
    $eventIndexes = $schema->getIndexes(MAIL_NOTIFICATION_GUARD_EVENTS);
    $scheduledColumns = $schema->getColumns(MAIL_NOTIFICATION_GUARD_SCHEDULED);
    $scheduledIndexes = $schema->getIndexes(MAIL_NOTIFICATION_GUARD_SCHEDULED);

    mailNotificationScheduledSchemaCreator()->down();
    mailNotificationSchemaCreator()->down();

    expect($schema->hasTable(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS))
        ->toBeTrue()
        ->and($schema->hasTable(MAIL_NOTIFICATION_GUARD_EVENTS))->toBeTrue()
        ->and($schema->hasTable(MAIL_NOTIFICATION_GUARD_SCHEDULED))->toBeTrue()
        ->and($schema->getColumns(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS))
        ->toBe($notificationColumns)
        ->and($schema->getIndexes(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS))
        ->toBe($notificationIndexes)
        ->and($schema->getColumns(MAIL_NOTIFICATION_GUARD_EVENTS))
        ->toBe($eventColumns)
        ->and($schema->getIndexes(MAIL_NOTIFICATION_GUARD_EVENTS))
        ->toBe($eventIndexes)
        ->and($schema->getColumns(MAIL_NOTIFICATION_GUARD_SCHEDULED))
        ->toBe($scheduledColumns)
        ->and($schema->getIndexes(MAIL_NOTIFICATION_GUARD_SCHEDULED))
        ->toBe($scheduledIndexes);
});

it('fails on an incompatible notification table before creating an event table', function () {
    $schema = configureMailNotificationGuardStorage();
    $schema->create(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 32)->default('pending');
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            'Existing mail notification table ['.MAIL_NOTIFICATION_GUARD_NOTIFICATIONS.'] is incompatible',
        )
        ->and($schema->hasTable(MAIL_NOTIFICATION_GUARD_EVENTS))->toBeFalse();
});

it('rejects a partially matching notification table missing a runtime column', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    $schema->drop(MAIL_NOTIFICATION_GUARD_EVENTS);
    $schema->table(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        function (Blueprint $table): void {
            $table->dropColumn('provider_occurred_at');
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(LogicException::class, 'provider_occurred_at')
        ->and($schema->hasTable(MAIL_NOTIFICATION_GUARD_EVENTS))->toBeFalse();
});

it('fails on an incompatible event table before creating a notification table', function () {
    $schema = configureMailNotificationGuardStorage();
    $schema->create(
        MAIL_NOTIFICATION_GUARD_EVENTS,
        function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('mail_notification_id');
            $table->string('provider', 128);
            $table->string('provider_event_id', 255);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('normalized_type', 32);
            $table->timestampTz('occurred_at', 6);
            $table->json('metadata')->nullable();
            $table->timestampTz('processed_at', 6);
            $table->timestampsTz(6);
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            'cannot be adopted without its configured notification table',
        )
        ->and($schema->hasTable(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS))
        ->toBeFalse();
});

it('allows fresh configured tables and accepts their created package schema', function () {
    $schema = configureMailNotificationGuardStorage();
    forgetMailNotificationSchemaCreator();

    mailNotificationCompatibilityGuard()->up();

    expect($schema->hasTable(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS))
        ->toBeFalse()
        ->and($schema->hasTable(MAIL_NOTIFICATION_GUARD_EVENTS))
        ->toBeFalse();

    mailNotificationSchemaCreator()->up();
    recordMailNotificationSchemaCreator();
    mailNotificationCompatibilityGuard()->up();

    expect($schema->hasTable(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS))
        ->toBeTrue()
        ->and($schema->hasTable(MAIL_NOTIFICATION_GUARD_EVENTS))
        ->toBeTrue();
});

it('rejects a compatible notification-only schema with ambiguous ownership', function () {
    $schema = configureMailNotificationGuardStorage();
    forgetMailNotificationSchemaCreator();
    mailNotificationSchemaCreator()->up();
    $schema->drop(MAIL_NOTIFICATION_GUARD_EVENTS);

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            'has ambiguous ownership because package schema creator',
        )
        ->and($schema->hasTable(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS))
        ->toBeTrue()
        ->and($schema->hasTable(MAIL_NOTIFICATION_GUARD_EVENTS))
        ->toBeFalse();
});

it('rejects compatible pre-existing pairs with ambiguous ownership', function () {
    configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    forgetMailNotificationSchemaCreator();

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            'have ambiguous ownership because package schema creator',
        );
});

it('accepts a compatible pair when the exact creator is recorded', function () {
    configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    recordMailNotificationSchemaCreator();

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->not->toThrow(LogicException::class);
});

it('accepts prefixed physical ownership in the preflight and doctor', function () {
    $schema = configureMailNotificationGuardStorage('tenant_');
    mailNotificationSchemaCreator()->up();
    recordMailNotificationSchemaCreator();

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->not->toThrow(LogicException::class);

    $foreignKey = $schema->getForeignKeys(
        MAIL_NOTIFICATION_GUARD_EVENTS,
    )[0] ?? null;
    $constraints = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.constraints');

    expect($foreignKey)
        ->not->toBeNull()
        ->and($foreignKey['foreign_table'] ?? null)
        ->toBe('tenant_'.MAIL_NOTIFICATION_GUARD_NOTIFICATIONS)
        ->and($constraints)
        ->not->toBeNull()
        ->passed->toBeTrue();
});

it('accepts schema-qualified PostgreSQL ownership in the preflight and doctor', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL schema-qualified coverage.');
    }

    $schemaName = 'nvl_mail_notifications_qualified_guard';
    $connectionName = DB::getDefaultConnection();
    $connection = DB::connection($connectionName);
    $schema = Schema::connection($connectionName);
    $notificationTable = $schemaName.'.'.MAIL_NOTIFICATION_GUARD_NOTIFICATIONS;
    $eventTable = $schemaName.'.'.MAIL_NOTIFICATION_GUARD_EVENTS;
    $scheduledTable = $schemaName.'.'.MAIL_NOTIFICATION_GUARD_SCHEDULED;

    $connection->statement(
        'drop schema if exists "nvl_mail_notifications_qualified_guard" cascade',
    );
    $connection->statement(
        'create schema "nvl_mail_notifications_qualified_guard"',
    );

    try {
        config()->set(
            'mail-notifications.storage.connection',
            $connectionName,
        );
        config()->set(
            'mail-notifications.storage.tables.notifications',
            $notificationTable,
        );
        config()->set(
            'mail-notifications.storage.tables.events',
            $eventTable,
        );
        config()->set(
            'mail-notifications.storage.tables.scheduled_messages',
            $scheduledTable,
        );

        mailNotificationSchemaCreator()->up();
        recordMailNotificationSchemaCreator();
        mailNotificationScheduledSchemaCreator()->up();
        recordMailNotificationMigration(MAIL_NOTIFICATION_SCHEDULED_CREATOR);

        expect(static fn () => mailNotificationCompatibilityGuard()->up())
            ->not->toThrow(LogicException::class);

        $foreignKey = $schema->getForeignKeys($eventTable)[0] ?? null;
        $constraints = collect(app(MailNotificationsDoctor::class)->inspect())
            ->firstWhere('key', 'schema.constraints');

        expect($foreignKey)
            ->not->toBeNull()
            ->and($foreignKey['foreign_schema'] ?? null)
            ->toBe($schemaName)
            ->and($foreignKey['foreign_table'] ?? null)
            ->toBe(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS)
            ->and($constraints)
            ->not->toBeNull()
            ->passed->toBeTrue();
    } finally {
        $connection->statement(
            'drop schema if exists "nvl_mail_notifications_qualified_guard" cascade',
        );
    }
});

it('rejects missing owned tables when the creator is recorded', function () {
    configureMailNotificationGuardStorage();
    recordMailNotificationSchemaCreator();

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            'is recorded, but its configured mail notification tables',
        );
});

it('rejects incompatible column types', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    $schema->table(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        function (Blueprint $table): void {
            $table->integer('provider')->nullable()->change();
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            MAIL_NOTIFICATION_GUARD_NOTIFICATIONS.'.provider type',
        );
});

it('rejects incompatible column nullability', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    $schema->table(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        function (Blueprint $table): void {
            $table->string('provider', 128)->nullable(false)->change();
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            MAIL_NOTIFICATION_GUARD_NOTIFICATIONS.'.provider nullability',
        );
});

it('rejects an incompatible notification status default', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    $schema->table(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        function (Blueprint $table): void {
            $table->string('status', 32)->default('accepted')->change();
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            MAIL_NOTIFICATION_GUARD_NOTIFICATIONS.'.status default',
        );
});

it('rejects a missing required unique constraint', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    $schema->table(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        function (Blueprint $table): void {
            $table->dropUnique(
                'mail_notifications_provider_message_unique',
            );
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(LogicException::class, 'provider message identity');
});

it('rejects a missing provider-event ownership cascade', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    recreateMailNotificationGuardEventTable(
        schema: $schema,
        withOwnershipForeignKey: false,
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(
            LogicException::class,
            'provider event ownership cascade',
        );
});

it('rejects a missing operational index', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    $schema->table(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        function (Blueprint $table): void {
            $table->dropIndex(
                'mail_notifications_status_created_index',
            );
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(LogicException::class, 'status timeline lookup');
});

it('rejects a missing status-change retention index', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    $schema->table(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
        function (Blueprint $table): void {
            $table->dropIndex(
                'mail_notifications_status_changed_index',
            );
        },
    );

    expect(static fn () => mailNotificationCompatibilityGuard()->up())
        ->toThrow(LogicException::class, 'status-change retention lookup');
});

it('leaves a compatible package schema untouched on up and down', function () {
    $schema = configureMailNotificationGuardStorage();
    mailNotificationSchemaCreator()->up();
    $notificationColumns = $schema->getColumns(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
    );
    $eventColumns = $schema->getColumns(MAIL_NOTIFICATION_GUARD_EVENTS);
    $notificationIndexes = $schema->getIndexes(
        MAIL_NOTIFICATION_GUARD_NOTIFICATIONS,
    );
    $eventIndexes = $schema->getIndexes(MAIL_NOTIFICATION_GUARD_EVENTS);

    $guard = mailNotificationCompatibilityGuard();
    $guard->up();
    $guard->down();

    expect($schema->getColumns(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS))
        ->toBe($notificationColumns)
        ->and($schema->getColumns(MAIL_NOTIFICATION_GUARD_EVENTS))
        ->toBe($eventColumns)
        ->and($schema->getIndexes(MAIL_NOTIFICATION_GUARD_NOTIFICATIONS))
        ->toBe($notificationIndexes)
        ->and($schema->getIndexes(MAIL_NOTIFICATION_GUARD_EVENTS))
        ->toBe($eventIndexes);
});
