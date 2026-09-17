<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;

return new class extends Migration
{
    /** Add nullable expansion columns before reviewed adoption. */
    public function up(): void
    {
        $connection = config('mail-notifications.storage.connection');
        $schema = Schema::connection(is_string($connection) && $connection !== '' ? $connection : null);

        foreach ([MailNotificationsTables::Notifications, MailNotificationsTables::ScheduledMessages] as $table) {
            if (! $schema->hasColumn($table, 'tenant_id')) {
                $schema->table($table, function (Blueprint $blueprint): void {
                    $blueprint->uuid('tenant_id')->nullable()->index();
                    $blueprint->string('ownership_key', 96)->default('platform')->index();
                });
            }
        }
        if (! $schema->hasColumn(MailNotificationsTables::ScheduledMessages, 'tenant_envelope')) {
            $schema->table(MailNotificationsTables::ScheduledMessages, function (Blueprint $table): void {
                $table->json('tenant_envelope')->nullable();
            });
        }
        if (! $schema->hasColumn(MailNotificationsTables::Events, 'tenant_id')) {
            $schema->table(MailNotificationsTables::Events, function (Blueprint $table): void {
                $table->uuid('tenant_id')->nullable()->index();
            });
        }
        $schema->getConnection()->table(MailNotificationsTables::ScheduledMessages)
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
