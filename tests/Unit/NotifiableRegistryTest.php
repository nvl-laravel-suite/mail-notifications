<?php

declare(strict_types=1);

use Nvl\MailNotifications\Contracts\ProvidesNotifiableTypes;
use Nvl\MailNotifications\Services\MailNotificationNotifiableTypeRegistry;
use Nvl\MailNotifications\Tests\Fixtures\ConflictingTrackable;
use Nvl\MailNotifications\Tests\Fixtures\TestTrackable;

it('registers stable host notifiable aliases', function () {
    $registry = new MailNotificationNotifiableTypeRegistry(
        configuredTypes: [
            'account' => TestTrackable::class,
        ],
    );

    expect($registry->resolve(' account '))->toBe(TestTrackable::class)
        ->and($registry->resolve('missing'))->toBeNull()
        ->and($registry->all())->toBe([
            'account' => TestTrackable::class,
        ]);
});

it('rejects conflicting host aliases', function () {
    expect(fn () => new MailNotificationNotifiableTypeRegistry(
        providers: [
            new class implements ProvidesNotifiableTypes
            {
                public function mailNotificationNotifiableTypes(): array
                {
                    return ['account' => ConflictingTrackable::class];
                }
            },
        ],
        configuredTypes: [
            'account' => TestTrackable::class,
        ],
    ))->toThrow(DomainException::class);
});

it('rejects invalid notifiable registry inputs', function (Closure $createRegistry) {
    expect($createRegistry)->toThrow(DomainException::class);
})->with([
    'empty alias' => [
        fn () => new MailNotificationNotifiableTypeRegistry(
            configuredTypes: [' ' => TestTrackable::class],
        ),
    ],
    'alias exceeding storage limit' => [
        fn () => new MailNotificationNotifiableTypeRegistry(
            configuredTypes: [
                str_repeat('a', 129) => TestTrackable::class,
            ],
        ),
    ],
    'invalid trackable class' => [
        fn () => new MailNotificationNotifiableTypeRegistry(
            configuredTypes: ['account' => stdClass::class],
        ),
    ],
    'invalid provider' => [
        fn () => new MailNotificationNotifiableTypeRegistry(
            providers: [new stdClass],
        ),
    ],
]);
