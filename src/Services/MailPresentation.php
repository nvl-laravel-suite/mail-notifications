<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\View\Factory;
use Illuminate\Mail\Markdown;
use Illuminate\Support\HtmlString;
use Illuminate\View\FileViewFinder;
use LogicException;

/**
 * Renders explicitly selected NVL components without adopting global mail paths.
 *
 * @api
 */
final class MailPresentation extends Markdown
{
    /** Configure native Markdown with host paths and the namespaced NVL theme. */
    public function __construct(Factory $views, private readonly MailTheme $mailTheme, private readonly MailPresentationContext $context)
    {
        $this->view = $views;
        $this->theme = 'nvl-mail-notifications::themes.default';
        $this->loadComponentsFrom([
            resource_path('views/vendor/nvl-mail-notifications'),
            dirname(__DIR__, 2).'/resources/views/mail',
        ]);
    }

    /** @param array<string, mixed> $data */
    public function render($view, array $data = [], $inliner = null): HtmlString
    {
        $this->useComponentFormat('html');

        $hostHints = $this->hostComponentHints();
        try {
            return $this->context->render(fn (): HtmlString => parent::render($view, $this->presentationData($data), $inliner));
        } finally {
            $this->view->replaceNamespace('mail', $hostHints);
        }
    }

    /** @param array<string, mixed> $data */
    public function renderText($view, array $data = []): HtmlString
    {
        $this->useComponentFormat('text');
        $hostHints = $this->hostComponentHints();
        try {
            return $this->context->render(fn (): HtmlString => parent::renderText($view, $this->presentationData($data)));
        } finally {
            $this->useComponentFormat('html');
            $this->view->replaceNamespace('mail', $hostHints);
        }
    }

    /** @return list<string> Preserve the native host component namespace before an explicit render. */
    private function hostComponentHints(): array
    {
        $finder = $this->view->getFinder();
        if (! $finder instanceof FileViewFinder) {
            throw new LogicException('Scoped NVL Markdown requires a view finder with inspectable namespace hints.');
        }
        $hints = $finder->getHints()['mail'] ?? [];
        $paths = [];
        foreach ($hints as $path) {
            if (! is_string($path)) {
                throw new LogicException('Mail component namespace hints must be string paths.');
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /** Select only the package-owned view namespace. */
    private function useComponentFormat(string $format): void
    {
        $paths = $format === 'html' ? $this->htmlComponentPaths() : $this->textComponentPaths();
        $this->view->replaceNamespace('nvl-mail-notifications', $paths);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function presentationData(array $data): array
    {
        return [...$data, 'nvlMailTheme' => $this->mailTheme->tokens(), 'nvlMailBrand' => $this->mailTheme->brand()];
    }
}
