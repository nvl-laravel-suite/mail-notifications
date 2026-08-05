<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Illuminate\Database\Schema\Builder;

/**
 * Inspects physical foreign-key ownership across prefixes and schemas.
 */
final class ForeignKeyInspector
{
    /**
     * Determine whether one owned table cascades to its configured owner.
     */
    public static function hasOwnershipCascade(
        Builder $schema,
        string $ownerTable,
        string $ownedTable,
        string $ownedColumn,
        string $ownerColumn,
    ): bool {
        [$ownerSchema, $ownerTableName] = $schema->parseSchemaAndTable(
            $ownerTable,
            true,
        );
        $physicalOwnerTable = $schema->getConnection()->getTablePrefix()
            .$ownerTableName;

        foreach ($schema->getForeignKeys($ownedTable) as $foreignKey) {
            $foreignSchema = $foreignKey['foreign_schema'];

            if ($foreignKey['columns'] === [$ownedColumn]
                && $foreignKey['foreign_table'] === $physicalOwnerTable
                && $foreignKey['foreign_columns'] === [$ownerColumn]
                && strtolower((string) ($foreignKey['on_delete'] ?? ''))
                    === 'cascade'
                && ($ownerSchema === null
                    || $foreignSchema === $ownerSchema)) {
                return true;
            }
        }

        return false;
    }
}
