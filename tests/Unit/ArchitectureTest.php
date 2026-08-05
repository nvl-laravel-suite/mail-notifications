<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('keeps core free from host module and provider SDK dependencies', function () {
    $packageRoot = dirname(__DIR__, 2);
    $adapterPath = $packageRoot.'/src/Adapters/MailerSend';
    $paths = [
        $packageRoot.'/src',
        $packageRoot.'/config',
        $packageRoot.'/database',
        $packageRoot.'/resources',
    ];
    $source = collect($paths)
        ->flatMap(static fn (string $path) => File::allFiles($path))
        ->reject(
            static fn (SplFileInfo $file): bool => str_starts_with(
                $file->getPathname(),
                $adapterPath,
            ),
        )
        ->map(static fn (SplFileInfo $file): string => $file->getContents())
        ->implode("\n");

    expect($source)
        ->not->toContain('Modules\\')
        ->not->toContain('Nwidart\\')
        ->not->toContain('Inertia\\')
        ->not->toContain('MailerSend\\')
        ->not->toContain('module_path(')
        ->not->toContain('Gift Come True')
        ->not->toContain('giftcometrue')
        ->not->toContain('gct-logo')
        ->not->toContain('emails/defaults')
        ->not->toContain('/assets/email-icons')
        ->not->toContain('/assets/media');
});

it('keeps the isolated MailerSend adapter free from its external SDK', function () {
    $packageRoot = dirname(__DIR__, 2);
    $source = collect(File::allFiles($packageRoot.'/src/Adapters/MailerSend'))
        ->map(static fn (SplFileInfo $file): string => $file->getContents())
        ->implode("\n");
    $composer = json_decode(
        File::get($packageRoot.'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($source)
        ->not->toMatch('/^use\s+MailerSend\\\\/m')
        ->not->toContain('\\MailerSend\\MailerSend')
        ->not->toContain('mailersend/mailersend')
        ->and($composer['require'] ?? [])->not->toHaveKey('mailersend/mailersend')
        ->and($composer['require-dev'] ?? [])->not->toHaveKey('mailersend/mailersend');
});

it('uses environment values only from package configuration', function () {
    $sourceFiles = File::allFiles(dirname(__DIR__, 2).'/src');
    $source = collect($sourceFiles)
        ->map(static fn (SplFileInfo $file): string => $file->getContents())
        ->implode("\n");

    expect($source)->not->toMatch('/\benv\s*\(/');
});
