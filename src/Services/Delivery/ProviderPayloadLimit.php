<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use RuntimeException;

/**
 * Enforces provider wire-payload limits before attempting submission.
 *
 * @internal
 */
class ProviderPayloadLimit
{
    public function __construct(private MailMediaStorage $media) {}

    /**
     * Reject a serialized payload larger than the configured integration's wire limit.
     */
    public function check(int $bytes, string $provider): void
    {
        $hardLimit = match ($provider) {
            'sendgrid' => 20000000,
            'mailgun' => 25000000,
            default => PHP_INT_MAX,
        };
        $fallback = $provider === 'mailersend' ? 35000000 : 25000000;
        $limit = min($hardLimit, max(1, ManagedConfiguration::integer('payload_limits.'.$provider, $fallback)));
        if ($bytes > $limit) {
            throw ValidationException::withMessages(['attachments' => 'The complete encoded message exceeds the '.number_format($limit).' byte limit for this provider. Reduce its content or attachments.']);
        }
    }

    /**
     * Encode and check the integration's JSON payload before an HTTP submission.
     *
     * @param  array<string, mixed>  $payload
     * @param  iterable<MessageAsset>  $assets
     */
    public function json(array $payload, iterable $assets, string $provider): void
    {
        $bytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->check($bytes, $provider);
        foreach ($assets as $asset) {
            $bytes += $this->encodedAttachmentBytes($asset);
            $this->check($bytes, $provider);
        }
    }

    private function encodedAttachmentBytes(MessageAsset $asset): int
    {
        $stream = $this->media->readStream($asset);
        if (! is_resource($stream)) {
            throw new RuntimeException('The attachment is unavailable.');
        }
        $hash = hash_init('sha256');
        $encodedBytes = 0;
        $rawBytes = 0;
        $buffer = '';
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 6144);
                if ($chunk === false) {
                    throw new RuntimeException('The attachment could not be read.');
                }
                $rawBytes += strlen($chunk);
                hash_update($hash, $chunk);
                $buffer .= $chunk;
                $length = strlen($buffer) - strlen($buffer) % 3;
                $encoded = base64_encode(substr($buffer, 0, $length));
                $encodedBytes += strlen($encoded) + substr_count($encoded, '/');
                $buffer = substr($buffer, $length);
            }
            $encoded = base64_encode($buffer);
            $encodedBytes += strlen($encoded) + substr_count($encoded, '/');
            if (! hash_equals($asset->checksum, hash_final($hash)) || (isset($asset->size) && $rawBytes !== $asset->size)) {
                throw new RuntimeException('The attachment no longer matches its accepted bytes.');
            }

            return $encodedBytes;
        } finally {
            fclose($stream);
        }
    }
}
