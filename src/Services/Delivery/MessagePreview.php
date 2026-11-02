<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use DOMDocument;
use Nvl\MailNotifications\Models\EmailMessage;

/**
 * Renders stored HTML and checked inline bytes under a restrictive preview content-security policy.
 */
class MessagePreview
{
    public function __construct(private MailMediaStorage $media) {}

    public const POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'none'; base-uri 'none'; sandbox";

    /**
     * Render stored HTML and checked inline bytes without submitting a provider attempt.
     */
    public function render(EmailMessage $message): string
    {
        $document = new DOMDocument;
        $errors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.($message->html ?? '<p>No HTML body.</p>'), LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }
        $assets = $message->messageAssets()->where('disposition', 'inline')->get()->keyBy('content_id');
        foreach (iterator_to_array($document->getElementsByTagName('img')) as $image) {
            if (! str_starts_with(strtolower($image->getAttribute('src')), 'cid:')) {
                continue;
            }
            $asset = $assets->get(substr($image->getAttribute('src'), 4));
            $status = $asset?->downloadStatus() ?? 'unavailable';
            if ($asset && $status === 'available' && in_array($asset->mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                $bytes = $this->media->bytes($asset);
                if (hash_equals($asset->checksum, hash('sha256', $bytes)) && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === $asset->mime_type) {
                    $image->removeAttribute('srcset');
                    $image->setAttribute('src', 'data:'.$asset->mime_type.';base64,'.base64_encode($bytes));

                    continue;
                }
            }
            $placeholder = $document->createElement('span');
            $placeholder->setAttribute('role', 'img');
            $placeholder->appendChild($document->createTextNode('Inline image '.$status.': '.($asset ? $asset->name : 'unavailable')));
            $image->parentNode?->replaceChild($placeholder, $image);
        }
        $html = $document->saveHTML();

        return '<!doctype html><meta charset="UTF-8"><meta http-equiv="Content-Security-Policy" content="'.htmlspecialchars(self::POLICY, ENT_QUOTES).'">'.$html;
    }
}
