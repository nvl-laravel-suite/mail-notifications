<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Real commits with no wrapping transaction, for tests of commit hooks, queues and stored state.
 *
 * A committed test reuses the schema the previous test left clean when it was migrated for the
 * same providers and configuration, and truncates its rows afterwards. The schema is rebuilt
 * otherwise, and after a test that changes the schema or the migration records, or replaces the connection. Bases whose
 * package tables live in in-memory SQLite keep rebuilding per test, which costs nothing there.
 */
trait CommitsTestDatabase
{
    public function refreshDatabase(): void
    {
        $connection = $this->app->make('db')->connection();
        $shared = $this->sharesCommittedSchema($connection);
        $fingerprint = TestCase::schemaFingerprint($this->app, $this->getPackageProviders($this->app));
        if (! $shared || ! RefreshDatabaseState::$migrated || TestCase::$migratedSchema !== $fingerprint) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->app[Kernel::class]->setArtisan(null);
        }
        RefreshDatabaseState::$migrated = $shared;
        TestCase::$migratedSchema = $fingerprint;
        $schemaChanged = false;
        $connection->beforeExecuting(static function (string $query) use (&$schemaChanged): void {
            if (preg_match('/\A\s*(?:create|alter|drop|rename)\s|\A\s*(?:insert\s+into|update|delete\s+from)\s+\W?migrations\b/i', $query) === 1) {
                $schemaChanged = true;
            }
        });
        $this->beforeApplicationDestroyed(function () use ($connection, $shared, &$schemaChanged): void {
            if (! $shared || $schemaChanged || $this->app->make('db')->connection() !== $connection) {
                RefreshDatabaseState::$migrated = false;

                return;
            }
            $this->truncateCommittedRows($connection);
        });
    }

    /** Only a persistent default connection that also holds the package tables can be reused. */
    protected function sharesCommittedSchema(Connection $connection): bool
    {
        return ! ($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:');
    }

    private function truncateCommittedRows(Connection $connection): void
    {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        $schema = $connection->getSchemaBuilder();
        $migrations = $connection->getTablePrefix().config('database.migrations.table', 'migrations');
        $schema->withoutForeignKeyConstraints(static function () use ($connection, $schema, $migrations): void {
            foreach ($schema->getTables($schema->getCurrentSchemaListing()) as $table) {
                if ($table['name'] !== $migrations && $connection->table($table['name'])->exists()) {
                    $connection->table($table['name'])->truncate();
                }
            }
        });
    }
}
