<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Closure;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\StatusConstraintInspector;
use Nvl\MailNotifications\ValueObjects\MailNotificationsDoctorCheck;
use Throwable;

/**
 * Inspects optional scheduled-mail configuration and storage without mutation.
 */
final readonly class ScheduledMailReadiness
{
    /**
     * Columns read or written by the scheduled-mail runtime.
     *
     * @var list<string>
     */
    private const array COLUMNS = [
        'id',
        'factory_alias',
        'payload_version',
        'payload',
        'to_recipients',
        'cc_recipients',
        'bcc_recipients',
        'status',
        'scheduled_for',
        'available_at',
        'attempts',
        'max_attempts',
        'last_attempt_at',
        'claim_token',
        'locked_until',
        'last_error',
        'notifiable_type',
        'notifiable_id',
        'metadata',
        'sent_at',
        'failed_at',
        'cancelled_at',
        'redacted_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Expected portable storage family for every runtime column.
     *
     * @var array<string, string>
     */
    private const array COLUMN_TYPES = [
        'id' => 'identity',
        'factory_alias' => 'string',
        'payload_version' => 'integer',
        'payload' => 'json',
        'to_recipients' => 'json',
        'cc_recipients' => 'json',
        'bcc_recipients' => 'json',
        'status' => 'string',
        'scheduled_for' => 'timestamp',
        'available_at' => 'timestamp',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'last_attempt_at' => 'timestamp',
        'claim_token' => 'identity',
        'locked_until' => 'timestamp',
        'last_error' => 'string',
        'notifiable_type' => 'string',
        'notifiable_id' => 'string',
        'metadata' => 'json',
        'sent_at' => 'timestamp',
        'failed_at' => 'timestamp',
        'cancelled_at' => 'timestamp',
        'redacted_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * Runtime columns that must accept null.
     *
     * @var list<string>
     */
    private const array NULLABLE_COLUMNS = [
        'cc_recipients',
        'bcc_recipients',
        'last_attempt_at',
        'claim_token',
        'locked_until',
        'last_error',
        'notifiable_type',
        'notifiable_id',
        'metadata',
        'sent_at',
        'failed_at',
        'cancelled_at',
        'redacted_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Declared lengths where database metadata exposes them.
     *
     * @var array<string, int>
     */
    private const array COLUMN_LENGTHS = [
        'id' => 36,
        'factory_alias' => 128,
        'status' => 32,
        'claim_token' => 36,
        'last_error' => 255,
        'notifiable_type' => 128,
        'notifiable_id' => 128,
    ];

    /**
     * Create the lazy scheduling readiness inspector.
     *
     * @param  Closure(): ScheduledMessageFactoryRegistry  $factories
     */
    public function __construct(
        private ScheduledMailConfiguration $configuration,
        private MailRetentionConfiguration $retention,
        private MailAnonymizationConfiguration $anonymization,
        private Closure $factories,
    ) {}

    /**
     * Return scheduling readiness checks without resolving factories when disabled.
     *
     * @return list<MailNotificationsDoctorCheck>
     */
    public function inspect(): array
    {
        try {
            $enabled = $this->configuration->enabled();
        } catch (Throwable $exception) {
            return [new MailNotificationsDoctorCheck(
                key: 'scheduling.configuration',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            )];
        }

        if (! $enabled) {
            $checks = [new MailNotificationsDoctorCheck(
                key: 'scheduling',
                severity: 'warning',
                passed: true,
                message: 'Scheduled mail is disabled; factory and schema checks were skipped.',
            )];

            if ($this->scheduledHistoryNeedsSchema()) {
                $checks[0] = new MailNotificationsDoctorCheck(
                    key: 'scheduling',
                    severity: 'warning',
                    passed: true,
                    message: 'Scheduled mail is disabled; factory checks were skipped, but schema is required by scheduled-history pruning or anonymization.',
                );
                $checks[] = $this->schemaCheck();
            }

            return $checks;
        }

        return [
            $this->configurationCheck(),
            $this->schemaCheck(),
        ];
    }

    /**
     * Validate all scheduling bounds and report the registered factory count.
     */
    private function configurationCheck(): MailNotificationsDoctorCheck
    {
        try {
            $batchSize = $this->configuration->batchSize();
            $claimTtl = $this->configuration->claimTtlSeconds();
            $maxAttempts = $this->configuration->defaultMaxAttempts();
            $maximumPayloadBytes = $this->configuration
                ->maximumPayloadBytes();
            $maximumRecipients = $this->configuration
                ->maximumRecipients();
            $this->configuration->retryDelaySeconds(1);
            $factoryCount = count(($this->factories)()->all());

            return new MailNotificationsDoctorCheck(
                key: 'scheduling.configuration',
                severity: 'error',
                passed: true,
                message: sprintf(
                    'Scheduled mail is enabled with %d factory/factories, batch size %d, claim TTL %d seconds, max attempts %d, payloads limited to %d bytes, and envelopes limited to %d recipients.',
                    $factoryCount,
                    $batchSize,
                    $claimTtl,
                    $maxAttempts,
                    $maximumPayloadBytes,
                    $maximumRecipients,
                ),
            );
        } catch (Throwable $exception) {
            return new MailNotificationsDoctorCheck(
                key: 'scheduling.configuration',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    /**
     * Require the configured table and every runtime column.
     */
    private function schemaCheck(): MailNotificationsDoctorCheck
    {
        $message = new ScheduledMailMessage;

        try {
            $schema = Schema::connection($message->getConnectionName());

            if (! $schema->hasTable($message->getTable())) {
                return new MailNotificationsDoctorCheck(
                    key: 'scheduling.schema',
                    severity: 'error',
                    passed: false,
                    message: "Table [{$message->getTable()}] is missing.",
                );
            }

            $missing = array_values(array_filter(
                self::COLUMNS,
                static fn (string $column): bool => ! $schema->hasColumn(
                    $message->getTable(),
                    $column,
                ),
            ));
            $invalidDefinitions = $this->invalidDefinitions(
                $schema,
                $message->getTable(),
            );
            $missingConstraints = $this->failedLabels([
                'scheduled message primary key' => $schema->hasIndex(
                    $message->getTable(),
                    ['id'],
                    'primary',
                ),
                'scheduled message status allowlist' => StatusConstraintInspector::matches(
                    connection: $schema->getConnection(),
                    table: $message->getTable(),
                    column: 'status',
                    constraint: 'scheduled_mail_messages_status_check',
                    allowedValues: array_map(
                        static fn (ScheduledMailStatus $status): string => $status->value,
                        ScheduledMailStatus::cases(),
                    ),
                ),
            ]);
            $missingIndexes = $this->failedLabels([
                'due-message claim lookup' => $schema->hasIndex(
                    $message->getTable(),
                    ['status', 'available_at', 'id'],
                ),
                'expired-claim recovery lookup' => $schema->hasIndex(
                    $message->getTable(),
                    ['status', 'locked_until'],
                ),
                'claim-token fencing lookup' => $schema->hasIndex(
                    $message->getTable(),
                    ['claim_token'],
                ),
                'notifiable timeline lookup' => $schema->hasIndex(
                    $message->getTable(),
                    ['notifiable_type', 'notifiable_id', 'created_at'],
                ),
                'sent retention lookup' => $schema->hasIndex(
                    $message->getTable(),
                    ['status', 'sent_at'],
                ),
                'failed retention lookup' => $schema->hasIndex(
                    $message->getTable(),
                    ['status', 'failed_at'],
                ),
                'cancelled retention lookup' => $schema->hasIndex(
                    $message->getTable(),
                    ['status', 'cancelled_at'],
                ),
                'legacy retention fallback lookup' => $schema->hasIndex(
                    $message->getTable(),
                    ['status', 'updated_at'],
                ),
                'privacy-stage lookup' => $schema->hasIndex(
                    $message->getTable(),
                    ['redacted_at', 'updated_at', 'id'],
                ),
            ]);
            $failures = [];

            if ($missing !== []) {
                $failures[] = 'missing columns: '.implode(', ', $missing);
            }

            if ($invalidDefinitions !== []) {
                $failures[] = 'incompatible definitions: '
                    .implode(', ', $invalidDefinitions);
            }

            if ($missingConstraints !== []) {
                $failures[] = 'missing constraints: '
                    .implode(', ', $missingConstraints);
            }

            if ($missingIndexes !== []) {
                $failures[] = 'missing indexes: '
                    .implode(', ', $missingIndexes);
            }

            return new MailNotificationsDoctorCheck(
                key: 'scheduling.schema',
                severity: 'error',
                passed: $failures === [],
                message: $failures === []
                    ? "Table [{$message->getTable()}] exposes compatible scheduled-mail columns, defaults, constraints, and operational indexes."
                    : sprintf(
                        'Table [%s] is incompatible: %s.',
                        $message->getTable(),
                        implode('; ', $failures),
                    ),
            );
        } catch (Throwable $exception) {
            return new MailNotificationsDoctorCheck(
                key: 'scheduling.schema',
                severity: 'error',
                passed: false,
                message: 'Scheduled-mail schema inspection failed: '
                    .$exception->getMessage(),
            );
        }
    }

    /**
     * Determine whether disabled scheduling still has retained privacy work.
     */
    private function scheduledHistoryNeedsSchema(): bool
    {
        try {
            return $this->retention->scheduledMessagePruningEnabled()
                || ($this->anonymization->enabled()
                    && $this->anonymization
                        ->scheduledMessageAnonymizationEnabled());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Return incompatible type, length, nullability, and default labels.
     *
     * @return list<string>
     */
    private function invalidDefinitions(Builder $schema, string $table): array
    {
        $columns = collect($schema->getColumns($table))->keyBy(
            static fn (array $column): string => strtolower(
                (string) $column['name'],
            ),
        );
        $failures = [];

        foreach (self::COLUMN_TYPES as $columnName => $family) {
            $column = $columns->get($columnName);

            if (! is_array($column)) {
                continue;
            }

            $label = "{$table}.{$columnName}";
            $typeName = strtolower((string) $column['type_name']);

            if (! $this->columnTypeMatches($typeName, $family)) {
                $failures[] = "{$label} type";
            }

            $expectedNullable = in_array(
                $columnName,
                self::NULLABLE_COLUMNS,
                true,
            );

            if ($column['nullable'] !== $expectedNullable) {
                $failures[] = "{$label} nullability";
            }

            $expectedLength = self::COLUMN_LENGTHS[$columnName] ?? null;
            $declaredType = strtolower((string) $column['type']);

            if ($expectedLength !== null
                && preg_match('/\((\d+)\)/', $declaredType, $matches) === 1
                && (int) $matches[1] !== $expectedLength) {
                $failures[] = "{$label} length";
            }
        }

        $status = $columns->get('status');

        if (is_array($status)
            && ! $this->isStringDefault(
                $status['default'] ?? null,
                'pending',
            )) {
            $failures[] = "{$table}.status default";
        }

        foreach (['attempts' => 0, 'max_attempts' => 3] as $name => $expected) {
            $column = $columns->get($name);

            if (is_array($column)
                && ! $this->isIntegerDefault(
                    $column['default'] ?? null,
                    $expected,
                )) {
                $failures[] = "{$table}.{$name} default";
            }
        }

        return $failures;
    }

    /**
     * Determine whether a database type belongs to the required family.
     */
    private function columnTypeMatches(string $typeName, string $family): bool
    {
        return match ($family) {
            'identity' => in_array($typeName, [
                'uuid',
                'char',
                'varchar',
                'character varying',
            ], true),
            'string' => in_array($typeName, [
                'char',
                'varchar',
                'character varying',
                'nvarchar',
            ], true),
            'integer' => in_array($typeName, [
                'bigint',
                'int',
                'int2',
                'int4',
                'int8',
                'integer',
                'mediumint',
                'smallint',
                'tinyint',
            ], true),
            'json' => in_array($typeName, [
                'json',
                'jsonb',
                'longtext',
                'text',
            ], true),
            'timestamp' => in_array($typeName, [
                'datetime',
                'datetimeoffset',
                'timestamp',
                'timestamp with time zone',
                'timestamptz',
            ], true),
            default => false,
        };
    }

    /**
     * Match a portable string default representation.
     */
    private function isStringDefault(mixed $default, string $expected): bool
    {
        if (! is_string($default)) {
            return false;
        }

        return preg_match(
            sprintf(
                '/^[\'"]?%s[\'"]?(?:::[a-z_ ]+)?$/i',
                preg_quote($expected, '/'),
            ),
            trim($default),
        ) === 1;
    }

    /**
     * Match a portable integer default representation.
     */
    private function isIntegerDefault(mixed $default, int $expected): bool
    {
        if (is_int($default)) {
            return $default === $expected;
        }

        if (! is_string($default)
            || preg_match(
                '/^[\'"]?(-?\d+)[\'"]?(?:::[a-z_ ]+)?$/i',
                trim($default),
                $matches,
            ) !== 1) {
            return false;
        }

        return (int) $matches[1] === $expected;
    }

    /**
     * Return labels for failed schema assertions.
     *
     * @param  array<string, bool>  $checks
     * @return list<string>
     */
    private function failedLabels(array $checks): array
    {
        return array_keys(array_filter(
            $checks,
            static fn (bool $passed): bool => ! $passed,
        ));
    }
}
