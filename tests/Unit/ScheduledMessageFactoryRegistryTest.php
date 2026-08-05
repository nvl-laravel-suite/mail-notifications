<?php

declare(strict_types=1);

use Nvl\MailNotifications\Exceptions\ScheduledMailException;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Tests\Fixtures\ScheduledTestFactory;

it('resolves factories only for explicitly supported payload versions', function () {
    $factory = new ScheduledTestFactory;
    $registry = new ScheduledMessageFactoryRegistry([$factory]);

    expect($registry->resolve(' test.scheduled ', 1))->toBe($factory)
        ->and($registry->all())->toHaveKey('test.scheduled', $factory)
        ->and(fn () => $registry->resolve('test.scheduled', 2))
        ->toThrow(ScheduledMailException::class, 'does not support')
        ->and(fn () => $registry->resolve('missing', 1))
        ->toThrow(ScheduledMailException::class, 'is not registered');
});

it('rejects invalid and duplicate factory aliases', function () {
    $first = new ScheduledTestFactory;
    $second = new ScheduledTestFactory;

    expect(fn () => new ScheduledMessageFactoryRegistry([new stdClass]))
        ->toThrow(ScheduledMailException::class, 'must implement')
        ->and(fn () => new ScheduledMessageFactoryRegistry([$first, $second]))
        ->toThrow(ScheduledMailException::class, 'already registered');
});
