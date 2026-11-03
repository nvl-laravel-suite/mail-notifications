<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

/**
 * Resolves and validates remote host addresses for outbound attachment and callback safety.
 *
 * @internal
 */
class PublicAddressResolver
{
    /**
     * Resolve host addresses for the outbound safety policy instead of trusting a caller-provided connection target.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static function (array $record): ?string {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            return is_string($address) ? $address : null;
        }, $records))));
    }
}
