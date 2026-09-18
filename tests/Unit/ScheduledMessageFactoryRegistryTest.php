<?php

declare(strict_types=1);

use Nvl\MailNotifications\Exceptions\ScheduledMailException;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Tests\Fixtures\ScheduledTestFactory;

it('resolves factories only for explicitly supported payload versions', function () {
    $factory = new ScheduledTestFactory;
    $registry = new ScheduledMessageFactoryRegistry([$factory]);

    expect($registry->resolve(' test.scheduled ', 1))->toBeInstanceOf(ScheduledTestFactory::class)
        ->and($registry->resolve('test.scheduled', 1))->not->toBe($factory)
        ->and($registry->all())->toHaveKey('test.scheduled')
        ->and(fn () => $registry->resolve('test.scheduled', 2))
        ->toThrow(ScheduledMailException::class, 'does not support')
        ->and(fn () => $registry->resolve('missing', 1))
        ->toThrow(ScheduledMailException::class, 'is not registered');
});

it('rejects invalid and conflicting factory aliases', function () {
    $first = new ScheduledTestFactory;

    expect(fn () => new ScheduledMessageFactoryRegistry([new stdClass]))
        ->toThrow(ScheduledMailException::class, 'must implement')
        ->and(new ScheduledMessageFactoryRegistry([$first, new ScheduledTestFactory])->all())
        ->toHaveKey('test.scheduled');
});
