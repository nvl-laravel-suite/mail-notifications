<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Services\ScheduledMailClaimer;
use Nvl\MailNotifications\Services\ScheduledMailFinalizer;
use Nvl\MailNotifications\Services\ScheduledMailProcessor;
use Nvl\MailNotifications\Services\ScheduledMailScheduler;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Tests\Fixtures\OwnershipScheduledFactory;
use Nvl\MailNotifications\Tests\OwnerScopeTestCase;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;

uses(OwnerScopeTestCase::class);

function nativeScheduleRequest(): ScheduleMailData
{
    return new ScheduleMailData('native.scheduled', 1, ['body' => 'Native message'],
        new ScheduledRecipients([new Recipient('recipient@example.test')]), CarbonImmutable::now('UTC'));
}

beforeEach(function (): void {
    app()->singleton(OwnershipScheduledFactory::class);
    app()->instance(ScheduledMessageFactoryRegistry::class, new ScheduledMessageFactoryRegistry([OwnershipScheduledFactory::class]));
});

it('resolves native scheduled factories fresh instead of retaining a previous owner constructor', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $registry = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor,
        fn () => new ScheduledMessageFactoryRegistry([OwnershipScheduledFactory::class]));
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, function () use ($registry): void {
        expect($registry->resolve('native.scheduled', 1)->createdForOwner)->toBe('8');
    });
});

it('claims and delivers only the admitted owner and preserves the immutable producer envelope', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $message = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, fn () => app(ScheduledMailScheduler::class)->schedule(nativeScheduleRequest()));
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, function (): void {
        expect(app(ScheduledMailClaimer::class)->claim())->toBe([]);
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function () use ($message): void {
        expect($message->getAttribute('ownership_envelope')['owner']['key'])->toBe('7');
        expect(fn () => $message->getConnection()->table($message->getTable())->where('id', $message->id)->update(['ownership_envelope' => '{}']))->toThrow(QueryException::class);
        expect(app(ScheduledMailProcessor::class)->process())->toBe(1)
            ->and(ScheduledMailMessage::query()->sole()->status)->toBe(ScheduledMailStatus::Sent);
    });
});

it('schedules and processes global mail without entering an owner or a generic central scope', function (): void {
    $message = app(ScheduledMailScheduler::class)->schedule(nativeScheduleRequest());
    expect($message->getAttribute('ownership_envelope')['mode'])->toBe('unresolved');
    expect(app(ScheduledMailProcessor::class)->process())->toBe(1)
        ->and(DB::table($message->getTable())->where('id', $message->id)->value('status'))->toBe('sent')
        ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('recovers expired claims after producer revocation without authorizing delivery', function (): void {
    $message = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () {
        app(ScheduledMailScheduler::class)->schedule(nativeScheduleRequest());

        return app(ScheduledMailClaimer::class)->claim()[0];
    });
    DB::table($message->getTable())->where('id', $message->id)->update(['locked_until' => CarbonImmutable::now('UTC')->subMinute()]);
    $this->allowOwners([]);
    config(['nvl-ownership.system_operations' => ['mail.recover-scheduled']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void {}
    });
    expect(Artisan::call('nvl:mail-notifications:recover-scheduled'))->toBe(0)
        ->and(DB::table($message->getTable())->where('id', $message->id)->value('status'))->toBe('pending');
    expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), fn () => app(ScheduledMailProcessor::class)->process()))
        ->toThrow(OwnershipViolation::class);
});

it('records a fenced delivery receipt under authorized recovery after the original producer is revoked', function (): void {
    $message = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () {
        app(ScheduledMailScheduler::class)->schedule(nativeScheduleRequest());

        return app(ScheduledMailClaimer::class)->claim()[0];
    });
    $this->allowOwners([]);
    config(['nvl-ownership.system_operations' => ['mail.recover-scheduled']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void {}
    });
    app(OwnershipRunner::class)->runSystem(app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7)), 'mail.recover-scheduled', function () use ($message): void {
        expect(app(ScheduledMailFinalizer::class)->markSent($message->id, $message->claim_token))->toBeTrue();
    });
    expect(DB::table($message->getTable())->where('id', $message->id)->value('status'))->toBe('sent');
});

it('rejects scheduled effects when the live producer envelope constraint is missing', function (): void {
    $message = app(ScheduledMailScheduler::class)->schedule(nativeScheduleRequest());
    $inspector = app(OwnershipConstraintInspector::class);
    $purpose = 'freeze:'.hash('sha256', json_encode([['ownership_envelope'], 'status', [], [], false], JSON_THROW_ON_ERROR));
    $connection = $message->getConnection();
    $name = $connection->getQueryGrammar()->wrap($inspector->name($connection, $message->getTable(), 'update', $purpose));
    $target = $connection->getQueryGrammar()->wrapTable($message->getTable());
    $connection->statement('DROP TRIGGER '.$name.($connection->getDriverName() === 'pgsql' ? ' ON '.$target : ''));
    expect(fn () => app(ScheduledMailProcessor::class)->process())->toThrow(OwnershipSchemaNotReady::class);
    expect(DB::table($message->getTable())->where('id', $message->id)->value('attempts'))->toBe(0);
});
