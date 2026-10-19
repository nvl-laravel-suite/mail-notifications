<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Ownership\MailIntegrityGuard;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** Captures mail authority and preserves global delivery as a fixed package capability. @internal */
final readonly class MailOwnershipEnvelope
{
    public function __construct(private OwnershipBoundary $boundary, private OwnershipContextResolver $context,
        private OwnershipQueueContext $queue, private OwnershipReadiness $readiness, private OwnershipResourceRegistry $resources,
        private MailTenantEnvelope $legacy, private MailIntegrityGuard $integrity) {}

    /** @return array<string,mixed> */
    public function notificationAttributes(): array
    {
        $this->assertStorage('mail.notifications');

        return $this->globalTracking() ? ['owner_scope_key' => 'central', 'owner_reference' => null] : $this->boundary->attributes('mail.notifications');
    }

    /** @return array<string,mixed> */
    public function scheduledAttributes(): array
    {
        $this->assertStorage('mail.scheduled');
        if ($this->resources->get('mail.scheduled')->ownership->strategy === 'unscoped') {
            return $this->legacy->scheduledAttributes();
        }

        return [...$this->notificationAttributes(), 'ownership_envelope' => $this->capture()->toArray()];
    }

    /** Validate the durable producer envelope separately from a recovery operation's actor. */
    public function assertScheduled(ScheduledMailMessage $message, bool $requireProducer = true): void
    {
        if ($this->resources->get('mail.scheduled')->ownership->strategy === 'unscoped') {
            $this->legacy->assertScheduled($message);

            return;
        }
        $global = $this->globalScheduling();
        if ($global) {
            $this->assertGlobalScheduledIdentity($message);
        } else {
            $this->boundary->assertRecord($message, 'mail.scheduled');
        }
        $wire = $message->getAttribute('ownership_envelope');
        if (! is_array($wire)) {
            throw new OwnershipViolation('Native scheduled mail requires its explicit producer envelope.');
        }
        $stored = OwnershipJobEnvelope::fromArray($wire);
        $current = $this->context->current()->snapshot();
        if ($global) {
            if ($stored->context()->generations !== $this->queue->captureUnowned()->context()->generations
                || ($requireProducer && ($stored->context()->mode !== OwnershipContextMode::Unresolved || $stored->toArray() !== $this->queue->captureUnowned()->toArray()))) {
                throw new OwnershipViolation('Global scheduled mail requires its unchanged explicit zero-authority envelope.');
            }

            return;
        }
        if ($stored->context()->owner != $current->owner || $stored->context()->generations !== $current->generations) {
            throw new OwnershipViolation('Scheduled mail and the admitted owner generation differ.');
        }
        if ($requireProducer && $stored->toArray() !== $this->capture()->toArray()) {
            $this->queue->run($stored, static fn (): null => null);
        }
    }

    public function assertNotification(MailNotification $notification): void
    {
        $this->boundary->assertRecord($notification, 'mail.notifications');
    }

    /** Require live package constraints without exposing a persistence capability. */
    public function assertStorage(string $resource): void
    {
        $this->integrity->assertReady($resource);
    }

    /** Identify only the unadmitted global tracking workflow, never an owner fallback. */
    public function globalTracking(): bool
    {
        $this->assertStorage('mail.notifications');
        if ($this->resources->get('mail.notifications')->ownership->strategy === 'unscoped'
            || $this->context->current()->snapshot()->mode !== OwnershipContextMode::Unresolved) {
            return false;
        }
        $this->readiness->assertActive('mail.notifications');
        $this->readiness->assertActive('mail.events');

        return true;
    }

    /** Read the verified producer capture only within its current admitted record partition. */
    public function producerEnvelope(ScheduledMailMessage $message): ?OwnershipJobEnvelope
    {
        if ($this->resources->get('mail.scheduled')->ownership->strategy === 'unscoped') {
            return null;
        }
        $this->assertScheduled($message);
        $wire = $message->getAttribute('ownership_envelope');

        return is_array($wire) ? OwnershipJobEnvelope::fromArray($wire) : throw new OwnershipViolation;
    }

    /** Keep central producer work separate from ordinary zero-authority global delivery. */
    public function centralScheduling(): bool
    {
        return $this->resources->get('mail.scheduled')->ownership->strategy !== 'unscoped'
            && $this->context->current()->snapshot()->mode === OwnershipContextMode::Central;
    }

    /** Restrict a central claim to its already admitted producer before opening the source transaction.
     * @return array<string,string>
     */
    public function centralProducerFilters(): array
    {
        if (! $this->centralScheduling()) {
            return [];
        }
        $snapshot = $this->capture()->context();
        $principal = $snapshot->principal ?? throw new OwnershipViolation;

        return ['ownership_envelope->principal->type' => $principal['type'], 'ownership_envelope->principal->id' => $principal['id'],
            'ownership_envelope->operation' => $snapshot->operation ?? throw new OwnershipViolation];
    }

    /** Validate global schedule storage without admitting any principal. */
    public function globalScheduling(): bool
    {
        if (! $this->globalTracking()) {
            return false;
        }
        $this->assertStorage('mail.scheduled');

        return true;
    }

    /** Require unchanged canonical stored global identity before a claim or finalization. */
    private function assertGlobalScheduledIdentity(ScheduledMailMessage $message): void
    {
        $canonical = new ScheduledMailMessage;
        $key = $message->getRawOriginal('id');
        if (! is_string($key) || ! $message->exists || $message->isDirty(['id', 'owner_scope_key', 'owner_reference', 'ownership_envelope'])
            || $message->getTable() !== $canonical->getTable() || $message->getConnection() !== $canonical->getConnection()
            || $message->getRawOriginal('owner_scope_key') !== 'central' || $message->getRawOriginal('owner_reference') !== null) {
            throw new OwnershipViolation('Global scheduled mail requires a persisted unchanged canonical record.');
        }
        $stored = $canonical->getConnection()->table($canonical->getTable())->useWritePdo()->where('id', $key)
            ->where('owner_scope_key', 'central')->whereNull('owner_reference')->first(['id', 'ownership_envelope']);
        if ($stored === null || ! is_string($stored->ownership_envelope)) {
            throw new OwnershipViolation('The global scheduled record no longer has its captured identity.');
        }
        try {
            $wire = json_decode($stored->ownership_envelope, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OwnershipViolation('The global schedule has an invalid producer envelope.');
        }
        $supplied = $message->getAttribute('ownership_envelope');
        if (! is_array($wire) || ! is_array($supplied)
            || OwnershipJobEnvelope::fromArray($wire)->toArray() !== OwnershipJobEnvelope::fromArray($supplied)->toArray()) {
            throw new OwnershipViolation('The global schedule differs from its stored producer envelope.');
        }
    }

    private function capture(): OwnershipJobEnvelope
    {
        return $this->context->current()->snapshot()->mode === OwnershipContextMode::Unresolved
            ? $this->queue->captureUnowned() : OwnershipJobEnvelope::capture($this->context->current());
    }
}
