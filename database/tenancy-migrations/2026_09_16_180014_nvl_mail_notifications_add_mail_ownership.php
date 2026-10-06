<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('mail-notifications');
    }

    /** Add nullable expansion columns before reviewed adoption. */
    public function up(): void
    {
        $schema = Schema::connection(PackageStorage::connection('mail-notifications'));

        foreach ([MailNotificationsTables::get(MailNotificationsTables::Notifications), MailNotificationsTables::get(MailNotificationsTables::ScheduledMessages)] as $table) {
            if (! $schema->hasColumn($table, 'tenant_id')) {
                $schema->table($table, function (Blueprint $blueprint): void {
                    $blueprint->uuid('tenant_id')->nullable()->index();
                    $blueprint->string('ownership_key', 96)->default('platform')->index();
                });
            }
        }
        if (! $schema->hasColumn(MailNotificationsTables::get(MailNotificationsTables::ScheduledMessages), 'tenant_envelope')) {
            $schema->table(MailNotificationsTables::get(MailNotificationsTables::ScheduledMessages), function (Blueprint $table): void {
                $table->json('tenant_envelope')->nullable();
            });
        }
        if (! $schema->hasColumn(MailNotificationsTables::get(MailNotificationsTables::Events), 'tenant_id')) {
            $schema->table(MailNotificationsTables::get(MailNotificationsTables::Events), function (Blueprint $table): void {
                $table->uuid('tenant_id')->nullable()->index();
            });
        }
        $schema->getConnection()->table(MailNotificationsTables::get(MailNotificationsTables::ScheduledMessages))
            ->whereNull('tenant_envelope')
            ->update(['tenant_envelope' => json_encode([
                'mode' => 'platform',
                'tenant_id' => null,
                'version' => 1,
            ], JSON_THROW_ON_ERROR)]);
    }

    /** Ownership history is retained on rollback. */
    public function down(): void {}
};
