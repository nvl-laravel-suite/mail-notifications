<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\ScheduledMailClaimer;

it('claims the next due PostgreSQL row while another worker holds the first', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Skipping locked claim rows requires a PostgreSQL connection.');
    }

    CarbonImmutable::setTestNow('2026-07-30 14:00:00 UTC');
    config()->set('nvl-mail-notifications.scheduling.enabled', true);
    config()->set('database.connections.claim-holder', config('database.connections.pgsql'));
    $messageIds = [];

    foreach ([0, 1] as $index) {
        $messageIds[] = ScheduledMailMessage::query()->create([
            'id' => (string) Str::uuid(),
            'factory_alias' => 'test.skip-locked',
            'payload_version' => 1,
            'payload' => ['sequence' => $index],
            'to_recipients' => [['email' => "skip-{$index}@example.test", 'name' => null]],
            'status' => ScheduledMailStatus::Pending,
            'scheduled_for' => CarbonImmutable::now('UTC')->addSeconds($index),
            'available_at' => CarbonImmutable::now('UTC')->subMinutes(2 - $index),
            'attempts' => 0,
            'max_attempts' => 3,
        ])->id;
    }

    $holder = DB::connection('claim-holder');
    $holder->beginTransaction();

    try {
        $holder->table(MailNotificationsTables::ScheduledMessages)
            ->where('id', $messageIds[0])
            ->lockForUpdate()
            ->get();
        DB::connection()->statement("SET lock_timeout TO '2s'");

        $claims = app(ScheduledMailClaimer::class)->claim(1);

        expect($claims)->toHaveCount(1)
            ->and($claims[0]->id)->toBe($messageIds[1]);
    } finally {
        $holder->rollBack();
        DB::purge('claim-holder');
        CarbonImmutable::setTestNow();
    }
});
