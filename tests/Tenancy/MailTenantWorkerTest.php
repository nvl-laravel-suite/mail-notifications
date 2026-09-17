<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Nvl\MailNotifications\Services\ScheduledMessageFactoryRegistry;
use Nvl\MailNotifications\Tests\Fixtures\ScheduledTestFactory;

it('stores factory classes and resolves a fresh factory for every tenant invocation', function (): void {
    $registry = new ScheduledMessageFactoryRegistry(
        [ScheduledTestFactory::class],
        Container::getInstance(),
    );

    $first = $registry->resolve('test.scheduled', 1);
    $second = $registry->resolve('test.scheduled', 1);

    expect($first)->toBeInstanceOf(ScheduledTestFactory::class)
        ->and($second)->toBeInstanceOf(ScheduledTestFactory::class)
        ->and($first)->not->toBe($second);
});
