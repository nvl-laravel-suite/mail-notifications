<?php

declare(strict_types=1);

use Nvl\MailNotifications\Contracts\ProviderAdapter;
use Nvl\MailNotifications\Services\ProviderRegistry;
use Nvl\MailNotifications\Tests\Fixtures\PluggedProviderAdapter;

it('normalizes and resolves provider adapters by stable name', function () {
    $adapter = new class implements ProviderAdapter
    {
        public function name(): string
        {
            return ' fixture-provider ';
        }
    };
    $registry = new ProviderRegistry([$adapter]);

    expect($registry->resolve(' fixture-provider '))->toBe($adapter)
        ->and($registry->all())->toHaveKey('fixture-provider', $adapter);
});

it('rejects invalid provider adapter registrations', function (array $adapters) {
    expect(fn () => new ProviderRegistry($adapters))
        ->toThrow(DomainException::class);
})->with([
    'invalid extension object' => [[new stdClass]],
    'empty provider name' => [[
        new class implements ProviderAdapter
        {
            public function name(): string
            {
                return ' ';
            }
        },
    ]],
    'conflicting provider name' => [[
        new class implements ProviderAdapter
        {
            public function name(): string
            {
                return 'duplicate';
            }
        },
        new class implements ProviderAdapter
        {
            public function name(): string
            {
                return 'duplicate';
            }
        },
    ]],
]);

it('rejects unknown provider adapter names', function () {
    expect(fn () => (new ProviderRegistry)->resolve('missing'))
        ->toThrow(DomainException::class, 'is not registered');
});

it('rejects distinct configured instances for one provider name', function () {
    expect(fn () => new ProviderRegistry([
        new PluggedProviderAdapter,
        new PluggedProviderAdapter,
    ]))->toThrow(DomainException::class, 'is already registered');
});
