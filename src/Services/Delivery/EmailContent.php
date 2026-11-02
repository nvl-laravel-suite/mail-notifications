<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use DateTimeInterface;
use DOMDocument;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Enums\EmailMessageType;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use TijsVerkoyen\CssToInlineStyles\Css\Processor;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Prepares rendered HTML and optional CSS while retaining the caller's original content snapshots.
 *
 * @phpstan-type MailRecipient array{email: string, name?: string|null}
 * @phpstan-type MailAttachment array{asset_id?: string, file?: \Illuminate\Http\UploadedFile, content_base64?: string, url?: string, name?: string, disposition?: 'attachment'|'inline', content_id?: string|null}
 * @phpstan-type ManagedMessageInput array{subject: string, to: list<MailRecipient>, cc?: list<MailRecipient>, bcc?: list<MailRecipient>, html?: string|null, text?: string|null, css?: string|null, type?: string|EmailMessageType, category?: string|null, language?: string|null, data?: array<array-key, mixed>, metadata?: array<array-key, mixed>, tags?: list<string>, references?: list<array{type: string, id: string}>, external_reference?: string|null, send_at?: string|DateTimeInterface|null, attachments?: list<string|MailAttachment>, inline_assets?: list<MailAttachment>}
 */
class EmailContent
{
    public function __construct(private CssToInlineStyles $inliner) {}

    /**
     * Prepare supplied HTML/CSS/text and retain original content; this service does not execute templates.
     *
     * @param  ManagedMessageInput  $input
     * @return array{subject: string, html: string|null, text: string|null}
     */
    public function prepare(array $input): array
    {
        $subject = $input['subject'];
        if (preg_match('/[\r\n]/', $subject) || mb_strlen($subject) > 998) {
            throw ValidationException::withMessages(['subject' => 'The subject is invalid.']);
        }
        $html = $input['html'] ?? null;
        $css = $input['css'] ?? null;
        if ($html !== null && $css !== null && $css !== '') {
            $maxBytes = ManagedConfiguration::integer('content.max_html_bytes', 10 * 1024 * 1024);
            $this->checkInliningBudget($html, $css, $maxBytes);
            $html = $this->inliner->convert($html, $css);
            $style = '<style type="text/css">'.str_ireplace('</style', '<\/style', $css).'</style>';
            $html = str_contains(strtolower($html), '</head>')
                ? preg_replace_callback('/<\/head>/i', fn (): string => $style.'</head>', $html, 1)
                : preg_replace_callback('/<body(?:\s[^>]*)?>/i', fn (array $match): string => '<head>'.$style.'</head>'.$match[0], $html, 1);
            if ($html === null || strlen($html) > $maxBytes) {
                $this->rejectInliningBudget();
            }
        }

        return ['subject' => $subject, 'html' => $html, 'text' => $input['text'] ?? null];
    }

    private function checkInliningBudget(string $html, string $css, int $maxBytes): void
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $cssBytes = strlen($css) + strlen((new Processor)->getCssFromStyleTags($html));
        $elementCount = $document->getElementsByTagName('*')->length;
        /** Attribute escaping and property formatting can expand each copied CSS byte. */
        $estimatedBytes = 12 * (strlen($html) + $cssBytes * ($elementCount + 1));
        if ($estimatedBytes > $maxBytes) {
            $this->rejectInliningBudget();
        }
    }

    private function rejectInliningBudget(): never
    {
        throw ValidationException::withMessages(['css' => 'CSS inlining exceeds the configured HTML size budget. Submit less CSS or prepared inline HTML.']);
    }
}
