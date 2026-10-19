<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\ScheduledMailScheduler;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Tests\Fixtures\OwnershipScheduledFactory;
use Nvl\MailNotifications\Tests\OwnerScopeTestCase;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnerScopeTestCase::class);

beforeEach(fn () => app()->instance(ScheduledMessageFactoryRegistry::class, new ScheduledMessageFactoryRegistry([OwnershipScheduledFactory::class])));

function ownerWorklistMailRequest(): ScheduleMailData
{
    return new ScheduleMailData('native.scheduled', 1, ['body' => 'Worklist message'],
        new ScheduledRecipients([new Recipient('recipient@example.test')]), CarbonImmutable::now('UTC'));
}

it('uses explicit owner references and fresh named system policies for scheduled mail fanout', function (): void {
    foreach ([7, 8] as $key) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($key), Actor::query()->findOrFail(1), fn () => app(ScheduledMailScheduler::class)->schedule(ownerWorklistMailRequest()));
    }
    expect(fn () => Artisan::call('nvl:mail-notifications:process-scheduled'))->toThrow(OwnershipViolation::class);
    config(['nvl-ownership.system_operations' => ['mail.process-scheduled']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void
        {
            expect($operation)->toBe('mail.process-scheduled')->and($owner->getKey())->toBeIn([7, 8]);
        }
    });
    expect(Artisan::call('nvl:mail-notifications:process-scheduled'))->toBe(0)
        ->and(DB::table((new ScheduledMailMessage)->getTable())->where('status', 'sent')->count())->toBe(2);
});

it('re-admits central scheduled producers explicitly instead of promoting ordinary global work', function (): void {
    config(['nvl-ownership.central_operations' => ['mail.central-send']]);
    app()->bind(CentralAccess::class, fn () => new class implements CentralAccess
    {
        public function assertCanRun(string $operation, Authenticatable $actor): void
        {
            if ($operation !== 'mail.central-send' || $actor->getAuthIdentifier() !== 1) {
                throw new OwnershipViolation;
            }
        }
    });
    app(OwnershipRunner::class)->central('mail.central-send', Actor::query()->findOrFail(1), fn () => app(ScheduledMailScheduler::class)->schedule(ownerWorklistMailRequest()));
    app(ScheduledMailScheduler::class)->schedule(ownerWorklistMailRequest());
    expect(Artisan::call('nvl:mail-notifications:process-scheduled'))->toBe(0)
        ->and(DB::table((new ScheduledMailMessage)->getTable())->where('status', 'sent')->count())->toBe(2);
});
