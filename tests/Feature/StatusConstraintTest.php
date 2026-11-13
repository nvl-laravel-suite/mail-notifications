<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\MailNotificationsDoctor;
use Nvl\MailNotifications\Support\StatusConstraintInspector;

const MAIL_STATUS_TEST_CONNECTION = 'mail-status-upgrade-test';
const MAIL_STATUS_TEST_NOTIFICATIONS = 'status_upgrade_mail_notifications';
const MAIL_STATUS_TEST_EVENTS = 'status_upgrade_mail_notification_events';
const MAIL_STATUS_TEST_SCHEDULED = 'status_upgrade_scheduled_mail_messages';

/**
 * Load the package tracking-table creator.
 */
function mailStatusTrackingCreator(): Migration
{
    return require dirname(__DIR__, 2)
        .'/database/migrations/2026_11_02_000000_nvl_mail_notifications_create_schema.php';
}

/**
 * Load the package scheduled-message creator.
 */
function mailStatusScheduledCreator(): Migration
{
    return require dirname(__DIR__, 2)
        .'/database/migrations/2026_11_02_000000_nvl_mail_notifications_create_schema.php';
}

/**
 * Return string values for the delivery-status enum.
 *
 * @return list<string>
 */
function mailDeliveryStatusValues(): array
{
    return array_map(
        static fn (MailDeliveryStatus $status): string => $status->value,
        MailDeliveryStatus::cases(),
    );
}

/**
 * Return string values for the scheduled-status enum.
 *
 * @return list<string>
 */
function scheduledMailStatusValues(): array
{
    return array_map(
        static fn (ScheduledMailStatus $status): string => $status->value,
        ScheduledMailStatus::cases(),
    );
}

/**
 * Exercise the inspector's exact native CHECK predicate parser.
 *
 * @param  list<string>  $allowedValues
 */
function nativeStatusClauseMatches(
    string $clause,
    string $column,
    array $allowedValues,
): bool {
    $method = new ReflectionMethod(
        StatusConstraintInspector::class,
        'checkClauseMatches',
    );

    return $method->invoke(
        null,
        $clause,
        $column,
        $allowedValues,
    ) === true;
}

/**
 * Drop one installed status invariant through its driver-native DDL.
 */
function dropMailStatusInvariant(
    Connection $connection,
    string $table,
    string $constraint,
): void {
    $grammar = $connection->getQueryGrammar();
    $driver = $connection->getDriverName();

    if ($driver === 'sqlite') {
        $connection->statement(sprintf(
            'drop trigger %s',
            $grammar->wrap($constraint.'_update'),
        ));

        return;
    }

    $dropClause = match ($driver) {
        'pgsql' => 'drop constraint',
        default => throw new LogicException(
            "Unsupported status-constraint test driver [{$driver}].",
        ),
    };
    $connection->statement(sprintf(
        'alter table %s %s %s',
        $grammar->wrapTable($table),
        $dropClause,
        $grammar->wrap($constraint),
    ));
}

/**
 * Configure an isolated schema produced entirely by the package creators.
 */
function configureMailStatusCreatorStorage(): Connection
{
    config()->set(
        'database.connections.'.MAIL_STATUS_TEST_CONNECTION,
        [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    );
    DB::purge(MAIL_STATUS_TEST_CONNECTION);
    config()->set(
        'nvl-mail-notifications.connection',
        MAIL_STATUS_TEST_CONNECTION,
    );
    config()->set(
        'nvl-mail-notifications.tables.notifications',
        MAIL_STATUS_TEST_NOTIFICATIONS,
    );
    config()->set(
        'nvl-mail-notifications.tables.events',
        MAIL_STATUS_TEST_EVENTS,
    );
    config()->set(
        'nvl-mail-notifications.tables.scheduled_messages',
        MAIL_STATUS_TEST_SCHEDULED,
    );
    mailStatusTrackingCreator()->up();

    return DB::connection(MAIL_STATUS_TEST_CONNECTION);
}

afterEach(function (): void {
    DB::purge(MAIL_STATUS_TEST_CONNECTION);
});

it('rejects an invalid raw notification status insert', function () {
    $notification = MailNotification::factory()->make();
    $attributes = $notification->getAttributes();
    $attributes['status'] = 'not-a-delivery-status';
    $attributes['created_at'] = now('UTC');
    $attributes['updated_at'] = now('UTC');

    expect(fn () => $notification->getConnection()
        ->table($notification->getTable())
        ->insert($attributes))
        ->toThrow(
            QueryException::class,
            'mail_notifications_status_check',
        );
});

it('rejects an invalid raw notification status write', function () {
    $notification = MailNotification::factory()->create();

    expect(fn () => $notification->getConnection()
        ->table($notification->getTable())
        ->where('id', $notification->id)
        ->update(['status' => 'not-a-delivery-status']))
        ->toThrow(QueryException::class);
});

it('rejects a noncanonical delivery status spelling', function (string $status) {
    $notification = MailNotification::factory()->create();

    expect(fn () => $notification->getConnection()
        ->table($notification->getTable())
        ->where('id', $notification->id)
        ->update(['status' => $status]))
        ->toThrow(
            QueryException::class,
            'mail_notifications_status_check',
        );
})->with([
    'case differs' => 'PENDING',
    'trailing space' => 'pending ',
]);

it('rejects an invalid raw provider event status insert', function () {
    $notification = MailNotification::factory()->create();
    $event = MailNotificationEvent::factory()->make([
        'mail_notification_id' => $notification->id,
    ]);
    $attributes = $event->getAttributes();
    $attributes['id'] = (string) Str::uuid();
    $attributes['normalized_type'] = 'not-a-delivery-status';
    $attributes['created_at'] = now('UTC');
    $attributes['updated_at'] = now('UTC');

    expect(fn () => $event->getConnection()
        ->table($event->getTable())
        ->insert($attributes))
        ->toThrow(
            QueryException::class,
            'mail_notification_events_normalized_type_check',
        );
});

it('rejects an invalid raw provider event status write', function () {
    $event = MailNotificationEvent::factory()->create();

    expect(fn () => $event->getConnection()
        ->table($event->getTable())
        ->where('id', $event->id)
        ->update(['normalized_type' => 'not-a-delivery-status']))
        ->toThrow(QueryException::class);
});

it('rejects an invalid raw scheduled message status insert', function () {
    $message = ScheduledMailMessage::factory()->make();
    $attributes = $message->getAttributes();
    $attributes['id'] = (string) Str::uuid();
    $attributes['status'] = 'not-a-scheduled-status';
    $attributes['created_at'] = now('UTC');
    $attributes['updated_at'] = now('UTC');

    expect(fn () => $message->getConnection()
        ->table($message->getTable())
        ->insert($attributes))
        ->toThrow(
            QueryException::class,
            'scheduled_mail_messages_status_check',
        );
});

it('rejects an invalid raw scheduled message status write', function () {
    $message = ScheduledMailMessage::factory()->create();

    expect(fn () => $message->getConnection()
        ->table($message->getTable())
        ->where('id', $message->id)
        ->update(['status' => 'not-a-scheduled-status']))
        ->toThrow(QueryException::class);
});

it('reports missing tracking and event status invariants', function () {
    $notification = new MailNotification;
    $event = new MailNotificationEvent;
    dropMailStatusInvariant(
        $notification->getConnection(),
        $notification->getTable(),
        'mail_notifications_status_check',
    );
    dropMailStatusInvariant(
        $event->getConnection(),
        $event->getTable(),
        'mail_notification_events_normalized_type_check',
    );

    $check = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'schema.constraints');

    expect($check)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('notification status allowlist')
        ->message->toContain('provider event status allowlist');
});

it('reports a missing scheduled message status invariant', function () {
    config()->set('nvl-mail-notifications.scheduling.enabled', true);
    $message = new ScheduledMailMessage;
    dropMailStatusInvariant(
        $message->getConnection(),
        $message->getTable(),
        'scheduled_mail_messages_status_check',
    );

    $check = collect(app(MailNotificationsDoctor::class)->inspect())
        ->firstWhere('key', 'scheduling.schema');

    expect($check)
        ->not->toBeNull()
        ->passed->toBeFalse()
        ->message->toContain('scheduled message status allowlist');
});

it('installs exact status invariants and removes them with the alpha schema', function () {
    $connection = configureMailStatusCreatorStorage();

    expect(StatusConstraintInspector::matches(
        connection: $connection,
        table: MAIL_STATUS_TEST_NOTIFICATIONS,
        column: 'status',
        constraint: 'mail_notifications_status_check',
        allowedValues: mailDeliveryStatusValues(),
    ))->toBeTrue()
        ->and(StatusConstraintInspector::matches(
            connection: $connection,
            table: MAIL_STATUS_TEST_EVENTS,
            column: 'normalized_type',
            constraint: 'mail_notification_events_normalized_type_check',
            allowedValues: mailDeliveryStatusValues(),
        ))->toBeTrue()
        ->and(StatusConstraintInspector::matches(
            connection: $connection,
            table: MAIL_STATUS_TEST_SCHEDULED,
            column: 'status',
            constraint: 'scheduled_mail_messages_status_check',
            allowedValues: scheduledMailStatusValues(),
        ))->toBeTrue();

    mailStatusTrackingCreator()->down();

    expect(StatusConstraintInspector::matches(
        connection: $connection,
        table: MAIL_STATUS_TEST_NOTIFICATIONS,
        column: 'status',
        constraint: 'mail_notifications_status_check',
        allowedValues: mailDeliveryStatusValues(),
    ))->toBeFalse()
        ->and(StatusConstraintInspector::matches(
            connection: $connection,
            table: MAIL_STATUS_TEST_EVENTS,
            column: 'normalized_type',
            constraint: 'mail_notification_events_normalized_type_check',
            allowedValues: mailDeliveryStatusValues(),
        ))->toBeFalse()
        ->and(StatusConstraintInspector::matches(
            connection: $connection,
            table: MAIL_STATUS_TEST_SCHEDULED,
            column: 'status',
            constraint: 'scheduled_mail_messages_status_check',
            allowedValues: scheduledMailStatusValues(),
        ))->toBeFalse();
});

it('rejects invalid statuses immediately in a fresh creator schema', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Creator fixture uses isolated SQLite.');
    }

    $connection = configureMailStatusCreatorStorage();
    $now = now('UTC');

    expect(static fn () => $connection
        ->table(MAIL_STATUS_TEST_SCHEDULED)
        ->insert([
            'id' => (string) Str::uuid(),
            'factory_alias' => 'status.creator',
            'payload_version' => 1,
            'payload' => '{}',
            'to_recipients' => '[]',
            'status' => 'legacy-invalid-status',
            'scheduled_for' => $now,
            'available_at' => $now,
            'attempts' => 0,
            'max_attempts' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]))
        ->toThrow(
            QueryException::class,
            'scheduled_mail_messages_status_check',
        )
        ->and(StatusConstraintInspector::exists(
            connection: $connection,
            table: MAIL_STATUS_TEST_NOTIFICATIONS,
            constraint: 'mail_notifications_status_check',
        ))->toBeTrue()
        ->and(StatusConstraintInspector::exists(
            connection: $connection,
            table: MAIL_STATUS_TEST_EVENTS,
            constraint: 'mail_notification_events_normalized_type_check',
        ))->toBeTrue()
        ->and($connection->table(MAIL_STATUS_TEST_SCHEDULED)->count())
        ->toBe(0);
});

it('accepts every current scheduled status in a fresh SQLite creator schema', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Creator fixture uses isolated SQLite.');
    }

    configureMailStatusCreatorStorage();

    foreach (ScheduledMailStatus::cases() as $status) {
        $message = ScheduledMailMessage::factory()->create([
            'status' => $status,
        ]);

        expect($message->refresh()->status)->toBe($status);
    }
});

it('refuses to adopt a configuration-drifted scheduled table', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Configuration-drift fixture uses isolated SQLite.');
    }

    $connection = configureMailStatusCreatorStorage();
    $driftedTable = 'status_drifted_scheduled_mail_messages';
    Schema::connection(MAIL_STATUS_TEST_CONNECTION)->create(
        $driftedTable,
        static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 32)->default('pending');
        },
    );
    config()->set(
        'nvl-mail-notifications.tables.scheduled_messages',
        $driftedTable,
    );

    expect(static fn () => mailStatusScheduledCreator()->up())
        ->toThrow(LogicException::class)
        ->and(StatusConstraintInspector::exists(
            connection: $connection,
            table: $driftedTable,
            constraint: 'scheduled_mail_messages_status_check',
        ))->toBeFalse();
});

it('rejects adversarial SQLite triggers with the expected literal set', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Adversarial trigger fixture is SQLite-specific.');
    }

    $notification = MailNotification::factory()->create();
    $connection = $notification->getConnection();
    $grammar = $connection->getQueryGrammar();
    $table = $notification->getTable();
    $constraint = 'mail_notifications_status_check';
    $values = implode(', ', array_map(
        static fn (string $value): string => "'{$value}'",
        mailDeliveryStatusValues(),
    ));
    $connection->statement(sprintf(
        'drop trigger %s',
        $grammar->wrap($constraint.'_insert'),
    ));
    $connection->statement(sprintf(
        'drop trigger %s',
        $grammar->wrap($constraint.'_update'),
    ));
    $connection->statement(sprintf(
        'create trigger %s before insert on %s for each row when new.%s in (%s) begin select raise(abort, %s); end',
        $grammar->wrap($constraint.'_insert'),
        $grammar->wrapTable($table),
        $grammar->wrap('status'),
        $values,
        "'reversed status predicate'",
    ));
    $connection->statement(sprintf(
        'create trigger %s before update of %s on %s for each row when new.%s not in (%s) and 1 = 0 begin select raise(abort, %s); end',
        $grammar->wrap($constraint.'_update'),
        $grammar->wrap('status'),
        $grammar->wrapTable($table),
        $grammar->wrap('status'),
        $values,
        "'ineffective status predicate'",
    ));

    expect(StatusConstraintInspector::matches(
        connection: $connection,
        table: $table,
        column: 'status',
        constraint: $constraint,
        allowedValues: mailDeliveryStatusValues(),
    ))->toBeFalse()
        ->and($connection->table($table)
            ->where('id', $notification->id)
            ->update(['status' => 'not-a-delivery-status']))
        ->toBe(1)
        ->and($connection->table($table)
            ->where('id', $notification->id)
            ->value('status'))
        ->toBe('not-a-delivery-status');
});

it('rejects a SQLite update invariant attached to the wrong operation', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Adversarial trigger fixture is SQLite-specific.');
    }

    $notification = MailNotification::factory()->create();
    $connection = $notification->getConnection();
    $grammar = $connection->getQueryGrammar();
    $table = $notification->getTable();
    $constraint = 'mail_notifications_status_check';
    $values = implode(', ', array_map(
        static fn (string $value): string => "'{$value}'",
        mailDeliveryStatusValues(),
    ));
    $connection->statement(sprintf(
        'drop trigger %s',
        $grammar->wrap($constraint.'_update'),
    ));
    $connection->statement(sprintf(
        'create trigger %s before insert on %s for each row when new.%s not in (%s) begin select raise(abort, %s); end',
        $grammar->wrap($constraint.'_update'),
        $grammar->wrapTable($table),
        $grammar->wrap('status'),
        $values,
        "'wrong trigger operation'",
    ));

    expect(StatusConstraintInspector::matches(
        connection: $connection,
        table: $table,
        column: 'status',
        constraint: $constraint,
        allowedValues: mailDeliveryStatusValues(),
    ))->toBeFalse()
        ->and($connection->table($table)
            ->where('id', $notification->id)
            ->update(['status' => 'not-a-delivery-status']))
        ->toBe(1);
});

it('recognizes only the exact PostgreSQL ANY status check', function () {
    $values = implode(', ', array_map(
        static fn (string $value): string => "'{$value}'::character varying",
        mailDeliveryStatusValues(),
    ));
    $prettyValues = implode(', ', array_map(
        static fn (string $value): string => "'{$value}'::character varying::text",
        mailDeliveryStatusValues(),
    ));
    $exact = "((status)::text = ANY ((ARRAY[{$values}])::text[]))";
    $pretty = "status::text = ANY (ARRAY[{$prettyValues}])";
    $ineffective = "((status)::text = ANY ((ARRAY[{$values}])::text[]) OR 1 = 1)";

    expect(nativeStatusClauseMatches(
        $exact,
        'status',
        mailDeliveryStatusValues(),
    ))->toBeTrue()
        ->and(nativeStatusClauseMatches(
            $pretty,
            'status',
            mailDeliveryStatusValues(),
        ))->toBeTrue()
        ->and(nativeStatusClauseMatches(
            $ineffective,
            'status',
            mailDeliveryStatusValues(),
        ))->toBeFalse();
});

it('requires PostgreSQL 18 check constraints to be enforced', function (
    string $serverVersion,
    bool $expectsEnforcementPredicate,
) {
    $values = implode(', ', array_map(
        static fn (string $value): string => "'{$value}'::character varying",
        mailDeliveryStatusValues(),
    ));
    $schema = $this->mock(SchemaBuilder::class);
    $schema->shouldReceive('parseSchemaAndTable')
        ->once()
        ->with('public.mail_notifications')
        ->andReturn(['public', MailNotificationsTables::Notifications]);
    $connection = $this->mock(Connection::class);
    $connection->shouldReceive('getDriverName')
        ->andReturn('pgsql');
    $connection->shouldReceive('getSchemaBuilder')
        ->once()
        ->andReturn($schema);
    $connection->shouldReceive('getTablePrefix')
        ->once()
        ->andReturn('');
    $connection->shouldReceive('getServerVersion')
        ->once()
        ->andReturn($serverVersion);
    $connection->shouldReceive('selectOne')
        ->once()
        ->withArgs(static function (
            string $query,
            array $bindings,
        ) use ($expectsEnforcementPredicate): bool {
            $expectation = expect($query);

            if ($expectsEnforcementPredicate) {
                $expectation->toContain(
                    'constraint_record.conenforced = true',
                );
            } else {
                $expectation->not->toContain(
                    'constraint_record.conenforced = true',
                );
            }

            return $bindings === [
                'public',
                MailNotificationsTables::Notifications,
                'mail_notifications_status_check',
            ];
        })
        ->andReturn((object) [
            'check_clause' => "status::text = ANY (ARRAY[{$values}])",
        ]);

    expect(StatusConstraintInspector::matches(
        connection: $connection,
        table: 'public.mail_notifications',
        column: 'status',
        constraint: 'mail_notifications_status_check',
        allowedValues: mailDeliveryStatusValues(),
    ))->toBeTrue();
})->with([
    'PostgreSQL 17' => ['17.6 (Homebrew)', false],
    'PostgreSQL 18' => ['18beta1', true],
]);

it('scopes PostgreSQL check metadata to the exact constrained relation', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Same-name constraint fixture is PostgreSQL-specific.');
    }

    $notification = new MailNotification;
    $connection = $notification->getConnection();
    $schema = Schema::connection($notification->getConnectionName());
    $shadowTable = 'mail_status_constraint_shadow';
    $schema->dropIfExists($shadowTable);
    $schema->create(
        $shadowTable,
        static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 32);
        },
    );
    $grammar = $connection->getQueryGrammar();
    $connection->statement(sprintf(
        'alter table %s add constraint %s check (%s in (%s))',
        $grammar->wrapTable($shadowTable),
        $grammar->wrap('mail_notifications_status_check'),
        $grammar->wrap('status'),
        "'shadow-only'",
    ));

    try {
        expect(StatusConstraintInspector::matches(
            connection: $connection,
            table: $notification->getTable(),
            column: 'status',
            constraint: 'mail_notifications_status_check',
            allowedValues: mailDeliveryStatusValues(),
        ))->toBeTrue()
            ->and(StatusConstraintInspector::matches(
                connection: $connection,
                table: $shadowTable,
                column: 'status',
                constraint: 'mail_notifications_status_check',
                allowedValues: mailDeliveryStatusValues(),
            ))->toBeFalse();
    } finally {
        $schema->dropIfExists($shadowTable);
    }
});
