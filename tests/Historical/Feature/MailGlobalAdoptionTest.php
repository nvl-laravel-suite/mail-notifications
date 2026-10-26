<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Mail\Markdown;
use Nvl\MailNotifications\Providers\MailNotificationsServiceProvider;
use Nvl\MailNotifications\Services\MailPresentation;

it('preserves host Markdown output and shared view data on installation', function (): void {
    $markdown = app(Markdown::class);
    $paths = config('mail.markdown.paths');
    $shared = app(Factory::class)->getShared();
    $before = $markdown->render('mail-notifications-tests::host-message')->toHtml();
    (new MailNotificationsServiceProvider(app()))->boot();
    expect(config('mail.markdown.paths'))->toBe($paths)
        ->and(app(Factory::class)->getShared())->toBe($shared)
        ->and($markdown->render('mail-notifications-tests::host-message')->toHtml())->toBe($before)
        ->and($shared)->not->toHaveKey('nvlMailTheme');
});

it('renders namespaced NVL presentation without adopting host Markdown paths', function (): void {
    config(['nvl-mail-notifications.presentation.tokens.primary' => '#123456', 'nvl-mail-notifications.presentation.brand.name' => 'Own Brand']);
    $paths = config('mail.markdown.paths');
    $host = app(Markdown::class)->render('mail-notifications-tests::host-message')->toHtml();
    $html = app(MailPresentation::class)->render('mail-notifications-tests::nvl-message')->toHtml();
    $text = app(MailPresentation::class)->renderText('mail-notifications-tests::nvl-message')->toHtml();
    expect($html)->toContain('Own Brand')->toContain('#123456')
        ->and($text)->toContain('Own Brand')
        ->and(config('mail.markdown.paths'))->toBe($paths)
        ->and(app(Markdown::class)->render('mail-notifications-tests::host-message')->toHtml())->toBe($host);
});

it('adopts Markdown paths and view variables independently', function (): void {
    config(['nvl-mail-notifications.presentation.global_markdown' => true]);
    (new MailNotificationsServiceProvider(app()))->boot();
    expect(config('mail.markdown.paths'))->toContain(realpath(dirname(__DIR__, 2).'/resources/views/mail'))
        ->and(app(Factory::class)->getShared())->not->toHaveKey('nvlMailTheme');
    config(['nvl-mail-notifications.presentation.global_markdown' => false, 'nvl-mail-notifications.presentation.global_view_data' => true]);
    (new MailNotificationsServiceProvider(app()))->boot();
    expect(app(Factory::class)->getShared())->toHaveKey('nvlMailTheme');
});

it('renders published NVL component overrides without changing host Markdown', function (): void {
    $filesystem = new Filesystem;
    $componentPath = resource_path('views/vendor/nvl-mail-notifications/html');
    $headerPath = $componentPath.'/header.blade.php';
    $originalHeader = $filesystem->exists($headerPath) ? $filesystem->get($headerPath) : null;
    $host = app(Markdown::class)->render('mail-notifications-tests::host-message')->toHtml();
    $paths = config('mail.markdown.paths');
    $filesystem->ensureDirectoryExists($componentPath);
    $filesystem->put($headerPath, '<tr><td>Published NVL header</td></tr>');
    app()->forgetInstance(MailPresentation::class);

    try {
        expect(app(MailPresentation::class)->render('mail-notifications-tests::nvl-message')->toHtml())
            ->toContain('Published NVL header')
            ->and(config('mail.markdown.paths'))->toBe($paths)
            ->and(app(Markdown::class)->render('mail-notifications-tests::host-message')->toHtml())->toBe($host);
    } finally {
        app()->forgetInstance(MailPresentation::class);
        if ($originalHeader === null) {
            $filesystem->delete($headerPath);
        } else {
            $filesystem->put($headerPath, $originalHeader);
        }
    }
});
