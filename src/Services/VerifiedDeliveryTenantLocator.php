<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use DomainException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException;
use Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Bootstraps only stored ownership after provider verification, never from webhook input. */
final readonly class VerifiedDeliveryTenantLocator
{
    /** Create the narrow verified-event locator. */
    public function __construct(private Repository $config) {}

    /** Resolve the exact stored notification partition without exposing message contents. */
    public function locate(VerifiedDeliveryEvent $event): TenantContextSnapshot
    {
        $query = MailNotification::query()->select(['id', 'tenant_id', 'provider', 'provider_message_id']);
        if ($event->correlationId !== null) {
            $query->where('correlation_id', $event->correlationId);
        } elseif ($event->providerMessageId !== null) {
            $query->where(static function (Builder $query) use ($event): void {
                $query->where('provider', $event->provider)
                    ->where('provider_message_id', $event->providerMessageId);
            });
        } else {
            throw new DomainException('A verified delivery event requires a correlation or provider message identifier.');
        }
        $matches = $query->limit(2)->get();
        if ($matches->count() > 1) {
            throw new AmbiguousDeliveryEventException;
        }
        $notification = $matches->first();
        if (! $notification instanceof MailNotification) {
            throw new UnmatchedDeliveryEventException;
        }
        if ($notification->provider !== null && ! hash_equals($notification->provider, $event->provider)) {
            throw new DomainException('The verified provider does not match stored delivery identity.');
        }
        if ($event->providerMessageId !== null && $notification->provider_message_id !== null
            && ! hash_equals($notification->provider_message_id, $event->providerMessageId)) {
            throw new DomainException('The verified provider message does not match stored delivery identity.');
        }
        if ($this->config->get('tenancy.enabled') !== true) {
            return new TenantContextSnapshot(TenantContextMode::Disabled);
        }
        if (! is_string($notification->tenant_id) || $notification->tenant_id === '') {
            return new TenantContextSnapshot(TenantContextMode::Platform);
        }

        return new TenantContextSnapshot(TenantContextMode::Tenant, new TenantId($notification->tenant_id));
    }
}
