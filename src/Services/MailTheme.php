<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;

/**
 * Resolves validated, email-safe presentation tokens and generic brand data.
 */
final readonly class MailTheme
{
    /**
     * Default theme tokens keyed by their public configuration names.
     *
     * @var array<string, string>
     */
    private const array DEFAULT_TOKENS = [
        'font_family' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif",
        'canvas' => '#f6f8fb',
        'surface' => '#ffffff',
        'text' => '#4b5563',
        'heading' => '#111827',
        'muted' => '#6b7280',
        'primary' => '#2563eb',
        'primary_hover' => '#1d4ed8',
        'primary_soft' => '#eff6ff',
        'accent' => '#7c3aed',
        'border' => '#e5e7eb',
        'info' => '#2563eb',
        'info_soft' => '#eff6ff',
        'success' => '#15803d',
        'success_soft' => '#f0fdf4',
        'warning' => '#a16207',
        'warning_soft' => '#fefce8',
        'danger' => '#b91c1c',
        'danger_soft' => '#fef2f2',
        'radius' => '14px',
        'component_radius' => '10px',
        'content_width' => '600px',
        'logo_max_width' => '200px',
        'logo_max_height' => '64px',
        'heading_1_size' => '28px',
        'heading_2_size' => '23px',
        'heading_3_size' => '18px',
        'subtitle_size' => '13px',
        'body_size' => '15px',
        'small_size' => '12px',
    ];

    /**
     * Create the theme resolver.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Return validated CSS token values.
     *
     * @return array<string, string>
     */
    public function tokens(): array
    {
        $configured = $this->config->get(
            'mail-notifications.presentation.tokens',
            [],
        );
        $configuredTokens = is_array($configured) ? $configured : [];
        $tokens = [];

        foreach (self::DEFAULT_TOKENS as $key => $fallback) {
            $value = $configuredTokens[$key] ?? $fallback;
            $tokens[$key] = $this->sanitizeToken(
                key: $key,
                value: is_string($value) ? $value : $fallback,
                fallback: $fallback,
            );
        }

        return $tokens;
    }

    /**
     * Return generic brand values with Laravel application fallbacks.
     *
     * @return array{
     *     header_enabled: bool,
     *     footer_enabled: bool,
     *     name: string,
     *     url: string,
     *     logo_url: string|null,
     *     logo_alt: string,
     *     support_text: string|null,
     *     footer_text: string|null
     * }
     */
    public function brand(): array
    {
        $configured = $this->config->get(
            'mail-notifications.presentation.brand',
            [],
        );
        $brand = is_array($configured) ? $configured : [];
        $applicationName = $this->config->get('app.name', 'Laravel');
        $applicationUrl = $this->config->get('app.url', 'http://localhost');
        $name = $this->nonEmptyString($brand['name'] ?? null)
            ?? (is_string($applicationName) ? $applicationName : 'Laravel');
        $url = $this->validUrl($brand['url'] ?? null)
            ?? $this->validUrl($applicationUrl)
            ?? 'http://localhost';
        $logoUrl = $this->validUrl($brand['logo_url'] ?? null);

        return [
            'header_enabled' => $this->boolean($brand['header_enabled'] ?? true, true),
            'footer_enabled' => $this->boolean($brand['footer_enabled'] ?? true, true),
            'name' => $name,
            'url' => $url,
            'logo_url' => $logoUrl,
            'logo_alt' => $this->nonEmptyString($brand['logo_alt'] ?? null) ?? $name,
            'support_text' => $this->nonEmptyString($brand['support_text'] ?? null),
            'footer_text' => $this->nonEmptyString($brand['footer_text'] ?? null),
        ];
    }

    /**
     * Sanitize one configured CSS token against its token family.
     */
    private function sanitizeToken(string $key, string $value, string $fallback): string
    {
        $value = trim($value);

        if (str_contains($key, 'font_family')) {
            return preg_match("/^[a-zA-Z0-9\\s,\\-'\\\"]+$/D", $value) === 1
                ? $value
                : $fallback;
        }

        if (in_array($key, [
            'radius',
            'component_radius',
            'content_width',
            'logo_max_width',
            'logo_max_height',
            'heading_1_size',
            'heading_2_size',
            'heading_3_size',
            'subtitle_size',
            'body_size',
            'small_size',
        ], true)) {
            return preg_match('/^\\d+(?:\\.\\d+)?(?:px|em|rem|%)$/D', $value) === 1
                ? $value
                : $fallback;
        }

        return preg_match('/^#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/D', $value) === 1
            ? $value
            : $fallback;
    }

    /**
     * Normalize one optional non-empty string.
     */
    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /**
     * Normalize a configured boolean without truthy string coercion.
     */
    private function boolean(mixed $value, bool $fallback): bool
    {
        return is_bool($value) ? $value : $fallback;
    }

    /**
     * Normalize one absolute HTTP or HTTPS URL.
     */
    private function validUrl(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }
}
