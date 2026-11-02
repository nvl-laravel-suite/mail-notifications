<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Support\ManagedConfiguration;

/**
 * Resolves safe callback destinations, allowing local origins only through explicit development policy.
 */
class ClientWebhookTarget
{
    public function __construct(private PublicAddressResolver $resolver, private RemoteAttachmentFetcher $addresses) {}

    /**
     * Validate and resolve a callback destination, permitting local origins only through explicit environment policy.
     *
     * @return array{host: string, port: int, address: string, scheme: string}
     */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            $this->reject();
        }
        $scheme = $parts['scheme'] ?? '';
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $origin = $scheme.'://'.strtolower($parts['host']).(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':'.$port);
        $environments = ManagedConfiguration::get('webhooks.local_environments', ['local', 'testing']);
        $origins = ManagedConfiguration::get('webhooks.local_origins', []);
        $local = is_array($environments) && in_array(app()->environment(), $environments, true)
            && is_array($origins) && in_array($origin, $origins, true);
        if ((! $local && ($scheme !== 'https' || $port !== 443)) || ! in_array($scheme, ['http', 'https'], true)) {
            $this->reject();
        }
        $addresses = $this->resolver->resolve($host);
        if ($addresses === [] && $local) {
            $addresses = gethostbynamel($host) ?: [];
        }
        if ($addresses === []) {
            $this->reject();
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP) || (! $local && ! $this->addresses->isPublicAddress($address))) {
                $this->reject();
            }
        }

        return ['host' => $host, 'port' => $port, 'address' => $addresses[0], 'scheme' => $scheme];
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['url' => 'A public HTTPS callback URL is required. Local origins require an explicit environment and origin allowlist.']);
    }
}
