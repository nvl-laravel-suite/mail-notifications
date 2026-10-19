<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Ownership;

use DomainException;
use Nvl\MailNotifications\Exceptions\AmbiguousDeliveryEventException;
use Nvl\MailNotifications\Exceptions\UnmatchedDeliveryEventException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\ValueObjects\VerifiedDeliveryEvent;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Projects only immutable stored identity after signature verification, never request owner fields. @internal */
final readonly class VerifiedDeliveryOwnerLocator
{
    public function __construct(private MailIntegrityGuard $readiness) {}

    /** Return a canonical owner reference or the explicitly global mail partition. */
    public function locate(VerifiedDeliveryEvent $event): ?OwnerReference
    {
        $this->readiness->assertReady('mail.notifications');
        $model = new MailNotification;
        $query = $model->getConnection()->table($model->getTable())->useWritePdo()
            ->select(['id', 'provider', 'provider_message_id', 'owner_scope_key', 'owner_reference']);
        if ($event->correlationId !== null) {
            $query->where('correlation_id', $event->correlationId);
        } elseif ($event->providerMessageId !== null) {
            $query->where('provider', $event->provider)->where('provider_message_id', $event->providerMessageId);
        } else {
            throw new DomainException('Verified delivery requires an exact stored correlation.');
        }
        $matches = $query->limit(2)->get();
        if ($matches->count() > 1) {
            throw new AmbiguousDeliveryEventException;
        }
        $record = $matches->first() ?? throw new UnmatchedDeliveryEventException;
        if (($record->provider !== null && (! is_string($record->provider) || ! hash_equals($record->provider, $event->provider)))
            || ($record->provider_message_id !== null && $event->providerMessageId !== null && (! is_string($record->provider_message_id) || ! hash_equals($record->provider_message_id, $event->providerMessageId)))) {
            throw new DomainException('Verified delivery differs from the stored provider identity.');
        }
        if ($record->owner_scope_key === 'central' && $record->owner_reference === null) {
            return null;
        }
        if (! is_string($record->owner_reference)) {
            throw new OwnershipViolation('Stored delivery has no complete canonical owner identity.');
        }
        $owner = OwnerReference::fromJson($record->owner_reference);
        if ($owner->scopeKey() !== $record->owner_scope_key) {
            throw new OwnershipViolation('Stored mail owner and physical partition differ.');
        }

        return $owner;
    }
}
