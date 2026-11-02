<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Support\ManagedConfiguration;

/**
 * Coordinates account/provider send budgets and persisted pauses before transport work.
 */
class ProviderSendingAllowance
{
    /**
     * Reserve the configured provider/account budget before attempting transport.
     */
    public function reserve(ProviderAccount $account): int
    {
        $key = 'provider-send:'.$account->id;
        $cooldown = $this->cachedUntil($key.':cooldown') - now()->getTimestamp();
        if ($cooldown > 0) {
            return $cooldown;
        }
        $limit = ManagedConfiguration::integer('sending_limits.accounts.'.$account->id, ManagedConfiguration::integer('sending_limits.providers.'.$account->provider, $account->provider === 'mailersend' ? 120 : 60));
        if ($limit === 0) {
            return 0;
        }
        if (RateLimiter::tooManyAttempts($key, max(1, $limit)) || RateLimiter::increment($key, 60) > max(1, $limit)) {
            return max(1, RateLimiter::availableIn($key));
        }

        return 0;
    }

    private function cachedUntil(string $key): int
    {
        $until = Cache::get($key, 0);
        if (! is_int($until)) {
            throw new \UnexpectedValueException('The provider cooldown cache must contain an integer timestamp.');
        }

        return $until;
    }

    /**
     * Persist provider/account pause timing so later work respects the same send restriction.
     */
    public function pause(ProviderAccount $account, int $seconds): void
    {
        $key = 'provider-send:'.$account->id.':cooldown';
        Cache::lock($key.':lock', 5)->block(1, function () use ($key, $seconds): void {
            $until = max($this->cachedUntil($key), now()->getTimestamp() + max(1, $seconds));
            Cache::put($key, $until, max(1, $until - now()->getTimestamp()));
        });
    }
}
