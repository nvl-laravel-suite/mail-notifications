<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\ServiceProvider;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Services\Delivery\ProviderManager;
use Nvl\MailNotifications\Services\Integrations\ProviderApiManager;
use Nvl\Support\OwnerRegistry;
use PDO;

/** Boots managed delivery only when the consumer explicitly enables it. */
final class ManagedDeliveryServiceProvider extends ServiceProvider
{
    private const int SQLITE_DETERMINISTIC = 0x00000800;

    /**
     * Register stable media-owner aliases and provider managers only for explicitly enabled delivery.
     */
    public function register(): void
    {
        $owners = $this->app->make(OwnerRegistry::class);
        $owners->registerPackage('mail.assets', Asset::class, 'mail-notifications');
        $owners->registerPackage('mail.message-assets', MessageAsset::class, 'mail-notifications');
        $this->app->singleton(ProviderManager::class);
        $this->app->singleton(ProviderApiManager::class);
    }

    /**
     * Install case-folding support on current and future SQLite connections used by the delivery graph.
     */
    public function boot(Dispatcher $events, DatabaseManager $database): void
    {
        $register = static function (PDO $pdo): void {
            if (method_exists($pdo, 'createFunction')) {
                $pdo->createFunction('mail_casefold', static fn (?string $value): ?string => $value === null ? null : mb_convert_case($value, MB_CASE_FOLD, 'UTF-8'), 1, self::SQLITE_DETERMINISTIC);
            } elseif (method_exists($pdo, 'sqliteCreateFunction')) {
                $pdo->sqliteCreateFunction('mail_casefold', static fn (?string $value): ?string => $value === null ? null : mb_convert_case($value, MB_CASE_FOLD, 'UTF-8'), 1, self::SQLITE_DETERMINISTIC);
            }
        };
        $events->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($register): void {
            if ($event->connection->getDriverName() === 'sqlite') {
                $register($event->connection->getPdo());
                $register($event->connection->getReadPdo());
            }
        });
        foreach ($database->getConnections() as $connection) {
            if ($connection->getDriverName() === 'sqlite') {
                $register($connection->getPdo());
                $register($connection->getReadPdo());
            }
        }
    }
}
