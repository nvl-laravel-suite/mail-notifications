<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Illuminate\Database\Connection;

/**
 * Inspects named database invariants that constrain persisted status values.
 */
final class StatusConstraintInspector
{
    /**
     * Determine whether any named invariant is installed for one status column.
     */
    public static function exists(
        Connection $connection,
        string $table,
        string $constraint,
    ): bool {
        if ($connection->getDriverName() === 'sqlite') {
            return self::sqliteTriggerDefinitions(
                $connection,
                $table,
                $constraint,
            ) !== [];
        }

        return self::checkClause(
            $connection,
            $table,
            $constraint,
        ) !== null;
    }

    /**
     * Determine whether the named invariant exactly matches an allowed status set.
     *
     * @param  list<string>  $allowedValues
     */
    public static function matches(
        Connection $connection,
        string $table,
        string $column,
        string $constraint,
        array $allowedValues,
    ): bool {
        if ($connection->getDriverName() === 'sqlite') {
            $definitions = self::sqliteTriggerDefinitions(
                $connection,
                $table,
                $constraint,
            );

            if (count($definitions) !== 2) {
                return false;
            }

            return self::sqliteTriggerMatches(
                definition: $definitions[$constraint.'_insert'] ?? null,
                trigger: $constraint.'_insert',
                table: self::tableParts($connection, $table)[1],
                column: $column,
                operation: 'insert',
                allowedValues: $allowedValues,
            ) && self::sqliteTriggerMatches(
                definition: $definitions[$constraint.'_update'] ?? null,
                trigger: $constraint.'_update',
                table: self::tableParts($connection, $table)[1],
                column: $column,
                operation: 'update',
                allowedValues: $allowedValues,
            );
        }

        $clause = self::checkClause(
            $connection,
            $table,
            $constraint,
        );

        return $clause !== null
            && self::checkClauseMatches(
                $connection->getDriverName(),
                $clause,
                $column,
                $allowedValues,
            );
    }

    /**
     * Return installed SQLite INSERT and UPDATE trigger definitions.
     *
     * @return array<string, string>
     */
    private static function sqliteTriggerDefinitions(
        Connection $connection,
        string $table,
        string $constraint,
    ): array {
        [, $tableName] = self::tableParts($connection, $table);
        $rows = $connection->select(
            <<<'SQL'
                select name, sql
                from sqlite_master
                where type = 'trigger'
                  and tbl_name = ?
                  and name in (?, ?)
                order by name
                SQL,
            [
                $tableName,
                $constraint.'_insert',
                $constraint.'_update',
            ],
        );

        $definitions = [];

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $definition = (array) $row;
            $name = $definition['name'] ?? null;
            $sql = $definition['sql'] ?? null;

            if (is_string($name) && is_string($sql)) {
                $definitions[$name] = $sql;
            }
        }

        return $definitions;
    }

    /**
     * Validate one exact SQLite trigger operation, predicate, and rejecting body.
     *
     * @param  list<string>  $allowedValues
     */
    private static function sqliteTriggerMatches(
        ?string $definition,
        string $trigger,
        string $table,
        string $column,
        string $operation,
        array $allowedValues,
    ): bool {
        if ($definition === null) {
            return false;
        }

        $triggerPattern = self::sqliteIdentifierPattern($trigger);
        $tablePattern = self::sqliteIdentifierPattern($table);
        $columnPattern = self::sqliteIdentifierPattern($column);
        $operationPattern = $operation === 'insert'
            ? 'insert'
            : 'update\s+of\s+'.$columnPattern;
        $pattern = '/^\s*create\s+trigger\s+'.$triggerPattern
            .'\s+before\s+'.$operationPattern
            .'\s+on\s+'.$tablePattern
            .'\s+for\s+each\s+row\s+when\s+(?<predicate>.+?)'
            .'\s+begin\s+select\s+raise\s*\(\s*abort\s*,\s*'
            ."'(?:''|[^'])*'\s*\)\s*;\s*end\s*;?\s*$/is";

        if (preg_match($pattern, $definition, $matches) !== 1) {
            return false;
        }

        return self::sqliteClauseMatches(
            $matches['predicate'],
            $column,
            $allowedValues,
        );
    }

    /**
     * Return a quoted-or-bare SQLite identifier pattern.
     */
    private static function sqliteIdentifierPattern(string $identifier): string
    {
        return '(?:"'.preg_quote(str_replace('"', '""', $identifier), '/')
            .'"|`'.preg_quote(str_replace('`', '``', $identifier), '/')
            .'`|\['.preg_quote(str_replace(']', ']]', $identifier), '/')
            .'\]|'.preg_quote($identifier, '/').')';
    }

    /**
     * Return one named CHECK clause for a PostgreSQL or MySQL-family table.
     */
    private static function checkClause(
        Connection $connection,
        string $table,
        string $constraint,
    ): ?string {
        [$schema, $tableName] = self::tableParts($connection, $table);
        $driver = $connection->getDriverName();

        if (StatusConstraintDatabase::unsupportedReason($connection)
            !== null) {
            return null;
        }

        if ($driver === 'pgsql') {
            $schema ??= $connection->scalar('select current_schema()');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $schema ??= $connection->getDatabaseName();
        } else {
            return null;
        }

        if (! is_string($schema) || $schema === '') {
            return null;
        }

        if ($driver === 'pgsql') {
            $enforcementPredicate = self::postgresConstraintEnforcementMetadataExists(
                $connection,
            )
                ? 'and constraint_record.conenforced = true'
                : 'and true';
            $row = $connection->selectOne(
                <<<SQL
                    select pg_catalog.pg_get_expr(
                        constraint_record.conbin,
                        constraint_record.conrelid,
                        true
                    ) as check_clause
                    from pg_catalog.pg_constraint as constraint_record
                    inner join pg_catalog.pg_class as relation
                        on relation.oid = constraint_record.conrelid
                    inner join pg_catalog.pg_namespace as namespace
                        on namespace.oid = relation.relnamespace
                    where constraint_record.contype = 'c'
                      and constraint_record.convalidated = true
                      {$enforcementPredicate}
                      and namespace.nspname = ?
                      and relation.relname = ?
                      and constraint_record.conname = ?
                    SQL,
                [$schema, $tableName, $constraint],
            );
        } elseif ($driver === 'mysql') {
            $row = $connection->selectOne(
                <<<'SQL'
                    select cc.check_clause as check_clause
                    from information_schema.table_constraints as tc
                    inner join information_schema.check_constraints as cc
                        on cc.constraint_catalog = tc.constraint_catalog
                        and cc.constraint_schema = tc.constraint_schema
                        and cc.constraint_name = tc.constraint_name
                    where tc.constraint_type = 'CHECK'
                      and tc.table_schema = ?
                      and tc.table_name = ?
                      and tc.constraint_name = ?
                      and upper(tc.enforced) = 'YES'
                SQL,
                [$schema, $tableName, $constraint],
            );
        } elseif (self::mariaDbCheckConstraintsIncludeTableName(
            $connection,
        )) {
            $row = $connection->selectOne(
                <<<'SQL'
                    select cc.check_clause as check_clause
                    from information_schema.table_constraints as tc
                    inner join information_schema.check_constraints as cc
                        on cc.constraint_catalog = tc.constraint_catalog
                        and cc.constraint_schema = tc.constraint_schema
                        and cc.constraint_name = tc.constraint_name
                        and cc.table_name = tc.table_name
                    where tc.constraint_type = 'CHECK'
                      and tc.table_schema = ?
                      and tc.table_name = ?
                      and tc.constraint_name = ?
                SQL,
                [$schema, $tableName, $constraint],
            );
        } else {
            $row = $connection->selectOne(
                <<<'SQL'
                    select cc.check_clause as check_clause
                    from information_schema.table_constraints as tc
                    inner join information_schema.check_constraints as cc
                        on cc.constraint_catalog = tc.constraint_catalog
                        and cc.constraint_schema = tc.constraint_schema
                        and cc.constraint_name = tc.constraint_name
                    where tc.constraint_type = 'CHECK'
                      and tc.table_schema = ?
                      and tc.table_name = ?
                      and tc.constraint_name = ?
                    SQL,
                [$schema, $tableName, $constraint],
            );
        }

        if (! is_object($row)) {
            return null;
        }

        $definition = (array) $row;
        $clause = $definition['check_clause'] ?? null;

        return is_string($clause) ? $clause : null;
    }

    /**
     * Determine whether PostgreSQL exposes per-constraint enforcement state.
     */
    private static function postgresConstraintEnforcementMetadataExists(
        Connection $connection,
    ): bool {
        if (preg_match(
            '/^\s*(\d+)/',
            $connection->getServerVersion(),
            $matches,
        ) !== 1) {
            return true;
        }

        return (int) $matches[1] >= 18;
    }

    /**
     * Determine whether MariaDB scopes CHECK metadata by table name.
     */
    private static function mariaDbCheckConstraintsIncludeTableName(
        Connection $connection,
    ): bool {
        if (preg_match(
            '/(?<!\d)(\d+\.\d+\.\d+)(?!\d)/',
            $connection->getServerVersion(),
            $matches,
        ) !== 1) {
            return false;
        }

        return version_compare($matches[1], '12.1.0', '>=');
    }

    /**
     * Resolve the physical schema and prefixed table name.
     *
     * @return array{0: ?string, 1: string}
     */
    private static function tableParts(
        Connection $connection,
        string $table,
    ): array {
        [$schema, $tableName] = $connection
            ->getSchemaBuilder()
            ->parseSchemaAndTable($table);

        return [
            is_string($schema) ? $schema : null,
            $connection->getTablePrefix().$tableName,
        ];
    }

    /**
     * Determine whether one SQL predicate names the column and exact values.
     *
     * @param  list<string>  $allowedValues
     */
    private static function sqliteClauseMatches(
        string $clause,
        string $column,
        array $allowedValues,
    ): bool {
        $columnPattern = 'new\s*\.\s*["`]?'
            .preg_quote($column, '/').'["`]?';
        $literal = self::stringLiteralPattern();
        $predicate = self::stripWrappingParentheses($clause);

        if (preg_match(
            '/^'.$columnPattern.'\s+not\s+in\s*\(\s*'
                .$literal.'(?:\s*,\s*'.$literal.')*\s*\)$/i',
            $predicate,
        ) !== 1) {
            return false;
        }

        return self::literalValuesMatch($predicate, $allowedValues);
    }

    /**
     * Determine whether a real CHECK clause has one accepted allowlist shape.
     *
     * @param  list<string>  $allowedValues
     */
    private static function checkClauseMatches(
        string $driver,
        string $clause,
        string $column,
        array $allowedValues,
    ): bool {
        $predicate = self::stripWrappingParentheses(
            self::normalizeCatalogClause($driver, $clause),
        );
        $identifier = '["`]?'.preg_quote($column, '/').'["`]?';
        $scalarCast = '(?:\s*::\s*(?:text|character\s+varying))?';
        $columnExpression = '(?:\(\s*'.$identifier.'\s*\)|'
            .$identifier.')'.$scalarCast;
        $literal = self::stringLiteralPattern();
        $literalList = $literal.'(?:\s*,\s*'.$literal.')*';
        $checkedColumnExpression = in_array(
            $driver,
            ['mysql', 'mariadb'],
            true,
        )
            ? '(?:binary\s+'.$identifier
                .'|cast\s*\(\s*'.$identifier
                .'\s+as\s+(?:binary(?:\s*\(\s*\d+\s*\))?'
                .'|char(?:acter)?\s+charset\s+binary)\s*\))'
            : $columnExpression;
        $inPattern = '/^'.$checkedColumnExpression.'\s+in\s*\(\s*'
            .$literalList.'\s*\)$/i';

        if (preg_match($inPattern, $predicate) === 1) {
            return self::literalValuesMatch($predicate, $allowedValues);
        }

        if ($driver !== 'pgsql') {
            return false;
        }

        $arrayCast = '(?:::\s*(?:text|character\s+varying)\s*\[\s*\])?';
        $arrayExpression = '(?:array\s*\[\s*'.$literalList
            .'\s*\]|\(\s*array\s*\[\s*'.$literalList
            .'\s*\]\s*\))\s*'.$arrayCast;
        $anyPattern = '/^'.$columnExpression
            .'\s*=\s*any\s*\(\s*'.$arrayExpression.'\s*\)$/i';

        return preg_match($anyPattern, $predicate) === 1
            && self::literalValuesMatch($predicate, $allowedValues);
    }

    /**
     * Normalize driver catalog escaping without changing the SQL predicate.
     */
    private static function normalizeCatalogClause(
        string $driver,
        string $clause,
    ): string {
        return in_array($driver, ['mysql', 'mariadb'], true)
            ? str_replace("\\'", "'", $clause)
            : $clause;
    }

    /**
     * Return the strict SQL string-literal pattern used by supported drivers.
     */
    private static function stringLiteralPattern(): string
    {
        return "(?:_[a-z0-9]+)?'(?:''|[^'])*'"
            .'(?:\s*::\s*(?:text|character\s+varying))*';
    }

    /**
     * Compare every SQL string literal with the exact expected allowlist.
     *
     * @param  list<string>  $allowedValues
     */
    private static function literalValuesMatch(
        string $predicate,
        array $allowedValues,
    ): bool {
        preg_match_all(
            "/'((?:''|[^'])*)'/",
            $predicate,
            $matches,
        );
        $actualValues = array_values(array_unique(array_map(
            static fn (string $value): string => str_replace(
                "''",
                "'",
                $value,
            ),
            $matches[1],
        )));
        $expectedValues = array_values(array_unique($allowedValues));
        sort($actualValues);
        sort($expectedValues);

        return $actualValues === $expectedValues;
    }

    /**
     * Remove only balanced parentheses that wrap one complete predicate.
     */
    private static function stripWrappingParentheses(string $clause): string
    {
        $clause = trim($clause);

        while (str_starts_with($clause, '(')
            && str_ends_with($clause, ')')
            && self::outerParenthesesWrap($clause)) {
            $clause = trim(substr($clause, 1, -1));
        }

        return $clause;
    }

    /**
     * Determine whether the first parenthesis closes at the predicate end.
     */
    private static function outerParenthesesWrap(string $clause): bool
    {
        $depth = 0;
        $inLiteral = false;
        $length = strlen($clause);

        for ($index = 0; $index < $length; $index++) {
            $character = $clause[$index];

            if ($character === "'") {
                if ($inLiteral
                    && $index + 1 < $length
                    && $clause[$index + 1] === "'") {
                    $index++;

                    continue;
                }

                $inLiteral = ! $inLiteral;

                continue;
            }

            if ($inLiteral) {
                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;

                if ($depth === 0 && $index !== $length - 1) {
                    return false;
                }
            }
        }

        return $depth === 0 && ! $inLiteral;
    }
}
