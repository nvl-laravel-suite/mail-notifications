<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Derives bounded retry timing from provider responses and local policy.
 */
class ProviderRetryDelay
{
    /**
     * Calculate bounded retry timing using provider delay hints and the persisted attempt policy.
     */
    public function seconds(Response $response, bool $dailyQuota = false): ?int
    {
        if ($response->status() !== 429) {
            return null;
        }
        $delay = $this->retryAfter($response->header('Retry-After'));
        if ($dailyQuota && $response->header('x-apiquota-remaining') === '0' && ($reset = $this->quotaReset($response->header('x-apiquota-reset'))) !== null) {
            $delay = max($delay ?? 0, $reset);
        }

        return $delay !== null && $delay > 0 ? $delay : null;
    }

    private function retryAfter(?string $header): ?int
    {
        if ($header === null || strlen($header) > 128) {
            return null;
        }
        $header = trim($header);
        if (preg_match('/\A\d+\z/', $header) === 1) {
            $seconds = filter_var($header, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            return $seconds === false || $seconds > 253402300799 - now()->getTimestamp() ? null : max(1, $seconds);
        }
        $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $header);

        return $date === false ? null : max(1, $date->getTimestamp() - now()->getTimestamp());
    }

    private function quotaReset(?string $header): ?int
    {
        if ($header === null || strlen($header) > 64 || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $header) !== 1) {
            return null;
        }
        try {
            return max(0, CarbonImmutable::parse($header)->getTimestamp() - now()->getTimestamp());
        } catch (Throwable) {
            return null;
        }
    }
}
