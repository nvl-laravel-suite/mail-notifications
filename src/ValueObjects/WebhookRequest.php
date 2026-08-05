<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\ValueObjects;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Carries the bounded provider webhook inputs needed for signature verification.
 */
final readonly class WebhookRequest
{
    public string $provider;

    /**
     * Normalized request headers keyed by their case-insensitive names.
     *
     * @var array<string, string>
     */
    public array $headers;

    public ?string $method;

    public ?string $uri;

    /**
     * Create a provider webhook request.
     *
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $provider,
        public string $body,
        array $headers,
        ?string $method = null,
        ?string $uri = null,
    ) {
        $normalizedProvider = trim($provider);
        $normalizedMethod = $method !== null
            ? strtoupper(trim($method))
            : null;

        if ($normalizedProvider === '' || mb_strlen($normalizedProvider) > 128) {
            throw new InvalidArgumentException(
                'Webhook requests require a provider name containing 1 to 128 characters.',
            );
        }

        if ($normalizedMethod === '' || $uri === '') {
            throw new InvalidArgumentException(
                'Webhook request methods and URIs cannot be empty when supplied.',
            );
        }

        $normalizedHeaders = [];

        foreach ($headers as $name => $value) {
            $normalizedHeaders[mb_strtolower(trim($name))] = trim($value);
        }

        $this->provider = $normalizedProvider;
        $this->headers = $normalizedHeaders;
        $this->method = $normalizedMethod;
        $this->uri = $uri;
    }

    /**
     * Create a provider webhook value object from a Laravel HTTP request.
     */
    public static function fromLaravelRequest(string $provider, Request $request): self
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = implode(',', $values);
        }

        return new self(
            provider: $provider,
            body: $request->getContent(),
            headers: $headers,
            method: $request->getMethod(),
            uri: $request->getRequestUri(),
        );
    }
}
