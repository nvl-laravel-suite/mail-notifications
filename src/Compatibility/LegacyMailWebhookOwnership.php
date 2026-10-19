<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Compatibility;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Services\DatabaseTrackingLifecycle;
use Nvl\MailNotifications\Services\VerifiedDeliveryTenantLocator;
use Nvl\MailNotifications\ValueObjects\TransitionResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/** Retains verified pre-cutover callbacks only through the original legacy authority. @internal */
final readonly class LegacyMailWebhookOwnership
{
    public function __construct(private Repository $config, private VerifiedDeliveryTenantLocator $locator,
        private TenantRunner $tenants, private TenantContext $context) {}

    public function apply(VerifiedDeliveryEvent $event, TrackingLifecycle $lifecycle): TransitionResult
    {
        if ($this->config->get('nvl-tenancy.enabled') !== true && ! $lifecycle instanceof DatabaseTrackingLifecycle) {
            return $lifecycle->apply($event);
        }
        $stored = $this->locator->locate($event);
        if ($stored->mode === TenantContextMode::Tenant) {
            return $this->tenants->run($stored->tenantId ?? throw new TenantBoundaryViolation, fn (): TransitionResult => $lifecycle->apply($event));
        }
        if ($stored->mode === TenantContextMode::Platform && $this->context->snapshot()->mode !== TenantContextMode::Platform) {
            throw new TenantBoundaryViolation('Platform mail callbacks require an explicitly authorized platform context.');
        }

        return $lifecycle->apply($event);
    }
}
