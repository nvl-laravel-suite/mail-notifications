<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;

it('leaves no partial MySQL schema behind so a failed creation can be re-run', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Forcing a schema-wide CHECK name collision requires a MySQL connection.');
    }

    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_02_000000_nvl_mail_notifications_create_schema.php';
    $notifications = MailNotificationsTables::get(MailNotificationsTables::Notifications);
    $marketing = MailNotificationsTables::get(MailNotificationsTables::MarketingOptOuts);
    $migration->down();
    // CHECK names are schema-wide, so this fails the third table's raw ALTER.
    DB::statement('create table nvl_mn_partial_blocker (id int, constraint scheduled_mail_messages_status_check check (id > 0))');

    try {
        expect(fn () => $migration->up())->toThrow(QueryException::class, 'scheduled_mail_messages_status_check')
            ->and(Schema::hasTable($notifications))->toBeFalse()
            ->and(Schema::hasTable(MailNotificationsTables::get(MailNotificationsTables::ScheduledMessages)))->toBeFalse();
    } finally {
        DB::statement('drop table nvl_mn_partial_blocker');
    }

    $migration->up();

    expect(Schema::hasTable($notifications))->toBeTrue()
        ->and(Schema::hasTable($marketing))->toBeTrue();
});
