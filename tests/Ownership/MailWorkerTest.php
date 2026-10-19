<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\ScheduledMailScheduler;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Tests\Fixtures\Ownership\NativeWorkerMail;
use Nvl\MailNotifications\Tests\Fixtures\Ownership\NativeWorkerScheduledFactory;
use Nvl\MailNotifications\Tests\OwnerScopeTestCase;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueScenario;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Symfony\Component\Process\Process;

uses(OwnerScopeTestCase::class);

it('delivers real queued and scheduled owner and global mail with a separate process and retry', function (): void {
    if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql') {
        $this->markTestSkipped('Requires an explicitly dedicated PostgreSQL source and independent worker.');
    }
    QueueScenario::install();
    config(['queue.default' => 'database']);
    app()->instance(ScheduledMessageFactoryRegistry::class, new ScheduledMessageFactoryRegistry([NativeWorkerScheduledFactory::class]));
    foreach ([[7, 'retry'], [8, 'other'], [null, 'global']] as [$owner, $label]) {
        $produce = function () use ($owner, $label): void {
            app(ScheduledMailScheduler::class)->schedule(new ScheduleMailData('native.worker', 1, ['body' => $label],
                new ScheduledRecipients([new Recipient('recipient@example.test')]), CarbonImmutable::now('UTC')));
            $capture = $owner === null ? app(OwnershipQueueContext::class)->captureUnowned() : OwnershipJobEnvelope::capture(app(OwnershipContext::class));
            Mail::to('recipient@example.test')->queue(new NativeWorkerMail($capture, 'queued-'.$label));
        };
        $owner === null ? $produce() : app(OwnershipRunner::class)->run(Company::query()->findOrFail($owner), Actor::query()->findOrFail(1), $produce);
    }
    $worker = new Process([PHP_BINARY, __DIR__.'/../Fixtures/Ownership/worker.php', json_encode(config('database.connections.sqlite'), JSON_THROW_ON_ERROR), json_encode(['driver' => 'database', 'storage_root' => sys_get_temp_dir()], JSON_THROW_ON_ERROR)], timeout: 45);
    $worker->mustRun();
    expect($worker->getOutput())->toBe('mail-worker-complete')
        ->and(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table((new ScheduledMailMessage)->getTable())->where('status', 'sent')->count())->toBe(3)
        ->and(DB::table((new ScheduledMailMessage)->getTable())->where('attempts', 2)->count())->toBe(1)
        ->and(DB::table((new MailNotification)->getTable())->where('status', 'accepted')->count())->toBe(6)
        ->and(DB::table('ownership_queue_probes')->where('stage', 'mail-build')->orderBy('label')->pluck('owner', 'label')->all())->toBe([
            'global' => 'unresolved', 'other' => '8', 'queued-global' => 'unresolved', 'queued-other' => '8', 'queued-retry' => '7', 'retry' => '7',
        ])
        ->and(DB::table('ownership_queue_probes')->where('stage', 'worker-after')->where('owner', '<>', 'unresolved')->count())->toBe(0);
});
