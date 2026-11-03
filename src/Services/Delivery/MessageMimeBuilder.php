<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailMessage;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * Builds provider-ready MIME from persisted content, recipients and retained attachments.
 *
 * @internal
 */
class MessageMimeBuilder
{
    public function __construct(private MailMediaStorage $media) {}

    /**
     * Build the persisted envelope, content and attachments into provider-ready MIME without host template rendering.
     */
    public function build(EmailMessage $message, EmailAttempt $attempt, bool $includeBcc = true): Email
    {
        $email = (new Email)->from(new Address($message->from_email, $message->from_name ?? ''))->subject($message->subject);
        foreach ($message->recipients as $recipient) {
            $address = new Address($recipient->email, $recipient->name ?? '');
            if ($recipient->type === 'to') {
                $email->addTo($address);
            } elseif ($recipient->type === 'cc') {
                $email->addCc($address);
            } elseif ($recipient->type === 'bcc' && $includeBcc) {
                $email->addBcc($address);
            } elseif ($recipient->type !== 'bcc') {
                throw new RuntimeException('Unsupported recipient type.');
            }
        }
        if ($message->reply_to_email !== null) {
            $email->replyTo(new Address($message->reply_to_email, $message->reply_to_name ?? ''));
        }
        if ($message->text !== null) {
            $email->text($message->text);
        }
        if ($message->html !== null) {
            $email->html($message->html);
        }
        $email->getHeaders()->addTextHeader('X-Email-Attempt', $attempt->id);
        foreach ($message->messageAssets as $asset) {
            $stream = $this->media->readStream($asset);
            if (! is_resource($stream)) {
                throw new RuntimeException('The attachment is unavailable.');
            }
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            if (! hash_equals($asset->checksum, hash_final($hash)) || ! rewind($stream)) {
                fclose($stream);
                throw new RuntimeException('The attachment no longer matches its accepted bytes.');
            }
            $part = new DataPart($stream, $asset->name, $asset->mime_type);
            if ($asset->disposition === 'inline') {
                $part->asInline();
                if ($asset->content_id !== null) {
                    $part->setContentId($asset->content_id);
                }
            }
            $email->addPart($part);
        }

        return $email;
    }

    /**
     * Validate the provider-ready message before a transport attempt can consume it.
     */
    public function validate(Email $email, ProviderPayloadLimit $limits, string $provider): void
    {
        $bytes = 0;
        foreach ($email->toIterable() as $chunk) {
            if (! is_string($chunk)) {
                throw new RuntimeException('The MIME payload contains invalid bytes.');
            }
            $bytes += strlen($chunk);
            $limits->check($bytes, $provider);
        }
    }

    /**
     * Produce the provider-ready temporary MIME stream; the caller owns and must close the returned resource.
     *
     * @return resource
     */
    public function stream(Email $email, ProviderPayloadLimit $limits, string $provider)
    {
        $stream = tmpfile();
        if (! is_resource($stream)) {
            throw new RuntimeException('The MIME payload could not be prepared.');
        }
        $bytes = 0;
        try {
            foreach ($email->toIterable() as $chunk) {
                if (! is_string($chunk)) {
                    throw new RuntimeException('The MIME payload contains invalid bytes.');
                }
                $bytes += strlen($chunk);
                $limits->check($bytes, $provider);
                if (fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('The MIME payload could not be stored.');
                }
            }
            rewind($stream);

            return $stream;
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }
}
