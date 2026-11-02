<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Nvl\Support\Config\PackageStorage;

/** Keeps managed routing identities and delivery records separate from host models. */
trait UsesManagedStorage
{
    /**
     * Resolve this record's logical table through canonical package storage configuration.
     */
    public function getTable(): string
    {
        return $this->table ?? ManagedConfiguration::table(static::TABLE);
    }

    /**
     * Resolve the package connection consistently for relations, transactions and background operations.
     */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? ManagedConfiguration::connection() ?? parent::getConnectionName());
    }
}
