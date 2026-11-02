<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('does not register delivery operations when the managed engine is disabled', function (): void {
    expect(config('nvl-mail-notifications.managed.enabled'))->toBeFalse();

    $commands = Artisan::all();

    expect(array_filter(array_keys($commands), fn (string $command): bool => str_starts_with($command, 'nvl:mail-notifications:delivery:')))->toBe([]);
    expect($commands)->toHaveKey('nvl:mail-notifications:prune');
});
