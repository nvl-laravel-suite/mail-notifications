<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Nvl\MailNotifications\Compatibility\LegacyMailWebhookOwnership;
use Nvl\MailNotifications\Contracts\TrackingLifecycle;
use Nvl\MailNotifications\Ownership\VerifiedDeliveryOwnerLocator;
use Nvl\MailNotifications\ValueObjects\TransitionResult;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Contracts\OwnershipSystemRunner;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Reconciles authenticated provider facts under freshly admitted stored ownership. @internal */
final readonly class MailDeliveryReconciler
{
    public function __construct(private OwnershipResourceRegistry $resources, private VerifiedDeliveryOwnerLocator $locator,
        private OwnershipSystemRunner $owners, private OwnershipQueueContext $queue, private LegacyMailWebhookOwnership $legacy) {}

    public function apply(VerifiedDeliveryEvent $event, TrackingLifecycle $lifecycle): TransitionResult
    {
        if ($this->resources->get('mail.notifications')->ownership->strategy === 'unscoped') {
            return $this->legacy->apply($event, $lifecycle);
        }
        $owner = $this->locator->locate($event);
        if ($owner !== null) {
            return $this->owners->runSystem($owner, 'mail.provider-callback', fn (): TransitionResult => $lifecycle->apply($event));
        }

        return $this->queue->run($this->queue->captureUnowned(), fn (): TransitionResult => $lifecycle->apply($event));
    }
}
