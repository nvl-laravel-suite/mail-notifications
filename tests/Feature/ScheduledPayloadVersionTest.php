<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Nvl\MailNotifications\Contracts\ScheduledMessageFactory;
use Nvl\MailNotifications\Exceptions\ScheduledMailException;
use Nvl\MailNotifications\Services\ScheduledMailScheduler;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Tests\Fixtures\ScheduledTestMail;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;
use Nvl\MailNotifications\ValueObjects\ScheduledRecipients;
use Nvl\MailNotifications\ValueObjects\ScheduleMailData;

beforeEach(function (): void {
    config()->set('nvl-mail-notifications.scheduling.enabled', true);
});

it('accepts payload versions up to the portable smallint maximum and rejects the first overflow', function () {
    $factory = new class implements ScheduledMessageFactory
    {
        public function alias(): string
        {
            return 'test.versioned';
        }

        public function supportsVersion(int $version): bool
        {
            return $version >= 1;
        }

        public function validate(int $version, array $payload): void {}

        public function make(ScheduledMessageData $message): Mailable
        {
            return new ScheduledTestMail('Versioned');
        }
    };
    $registry = new ScheduledMessageFactoryRegistry([$factory]);
    app()->instance(ScheduledMessageFactoryRegistry::class, $registry);
    app()->forgetInstance(ScheduledMailScheduler::class);
    $request = static fn (int $version): ScheduleMailData => new ScheduleMailData(
        factoryAlias: 'test.versioned',
        payloadVersion: $version,
        payload: ['body' => 'Versioned'],
        recipients: new ScheduledRecipients([new Recipient('versioned@example.test')]),
        scheduledFor: CarbonImmutable::now('UTC'),
    );
    $scheduler = app(ScheduledMailScheduler::class);

    $message = $scheduler->schedule($request(32_767));
    $replaced = $scheduler->replacePending($message->id, $request(32_767));

    expect($message->fresh()?->payload_version)->toBe(32_767)
        ->and($replaced->payload_version)->toBe(32_767)
        ->and(fn () => $request(32_768))->toThrow(InvalidArgumentException::class, '1 and 32767')
        ->and(fn () => $registry->resolve('test.versioned', 32_768))->toThrow(ScheduledMailException::class, 'does not support payload version');
});
