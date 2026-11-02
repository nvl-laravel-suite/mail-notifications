<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Fetches bounded remote attachment bytes with public-address, redirect and transport safeguards.
 */
class RemoteAttachmentFetcher
{
    public function __construct(private PublicAddressResolver $resolver) {}

    /**
     * Fetch bounded remote bytes while rejecting unsafe addresses, redirects and unsupported responses.
     *
     * @return resource
     */
    public function fetch(string $url, int $limit)
    {
        $stream = tmpfile();
        try {
            $parts = parse_url($url);
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || ($parts['port'] ?? 443) !== 443 || isset($parts['fragment'])) {
                throw new \RuntimeException;
            }
            $host = trim($parts['host'], '[]');
            $addresses = $this->resolver->resolve($host);
            if ($addresses === []) {
                throw new \RuntimeException;
            }
            foreach ($addresses as $address) {
                if (! $this->isPublicAddress($address)) {
                    throw new \RuntimeException;
                }
            }
            $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
            $deadline = microtime(true) + 20;
            $response = Http::connectTimeout(5)->timeout(20)->setHandler(new CurlHandler)->withOptions([
                'allow_redirects' => false, 'sink' => $stream, 'proxy' => '', 'protocols' => ['https'],
                'decode_content' => false, 'headers' => ['Accept-Encoding' => 'identity'],
                'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$address]],
                'on_headers' => function (ResponseInterface $response) use ($limit): void {
                    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || (int) $response->getHeaderLine('Content-Length') > $limit || ! in_array($response->getHeaderLine('Content-Encoding'), ['', 'identity'], true)) {
                        throw new \RuntimeException;
                    }
                },
                'progress' => fn (int $downloadSize, int $downloaded): bool => $downloadSize > $limit || $downloaded > $limit || microtime(true) > $deadline,
            ])->get($url);
            $stats = fstat($stream);
            if (! $response->successful() || $stats === false || $stats['size'] > $limit || (int) $response->header('Content-Length') > $limit || ! in_array($response->header('Content-Encoding'), [null, '', 'identity'], true)) {
                throw new \RuntimeException;
            }
            $response->toPsrResponse()->getBody()->detach();
            rewind($stream);

            return $stream;
        } catch (Throwable) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw ValidationException::withMessages(['attachments' => 'The remote attachment could not be safely downloaded within the limits.']);
        }
    }

    /**
     * Determine whether an address is eligible for an external fetch under the public-address policy.
     */
    public function isPublicAddress(string $address): bool
    {
        if (str_contains($address, ':')) {
            $packed = @inet_pton($address);
            if ($packed === false || strlen($packed) !== 16 || substr($packed, 0, 2) < "\x20\x00" || substr($packed, 0, 2) > "\x3f\xff") {
                return false;
            }
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) === $address;
    }
}
