<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/** Encodes and validates scalar persisted mail ownership without serializing scoped services. */
final readonly class MailTenantEnvelope
{
    /** Create the ownership codec. */
    public function __construct(
        private TenantBoundary $boundary,
        private TenantContext $context,
        private Repository $config,
    ) {}

    /** @return array<string, mixed> */
    public function scheduledAttributes(): array
    {
        if ($this->config->get('tenancy.enabled') !== true) {
            return [];
        }

        return [
            ...$this->boundary->attributes('mail.scheduled'),
            'tenant_envelope' => $this->encode(TenantJobEnvelope::capture($this->context)),
        ];
    }

    /** @return array<string, mixed> */
    public function notificationAttributes(): array
    {
        return $this->boundary->attributes('mail.notifications');
    }

    /** Require a scheduled record and its durable envelope to match the active scope. */
    public function assertScheduled(ScheduledMailMessage $message): TenantJobEnvelope
    {
        $this->boundary->assertRecord($message, 'mail.scheduled');
        if ($this->config->get('tenancy.enabled') !== true) {
            return new TenantJobEnvelope(new TenantContextSnapshot(TenantContextMode::Disabled));
        }
        $envelope = $this->decode($message->tenant_envelope);
        $current = TenantJobEnvelope::capture($this->context);
        if ($this->encode($envelope) !== $this->encode($current)) {
            throw new TenantBoundaryViolation('Scheduled mail envelope does not match the active tenant context.');
        }

        return $envelope;
    }

    /** Require one tracking row to belong to the active scope. */
    public function assertNotification(MailNotification $notification): void
    {
        $this->boundary->assertRecord($notification, 'mail.notifications');
    }

    /** @return array{mode: string, tenant_id: string|null, version: int} */
    public function encode(TenantJobEnvelope $envelope): array
    {
        return [
            'mode' => $envelope->context->mode->value,
            'tenant_id' => $envelope->context->tenantId?->value,
            'version' => $envelope->version,
        ];
    }

    /** @param array<string, mixed>|null $value */
    public function decode(?array $value): TenantJobEnvelope
    {
        $mode = $value['mode'] ?? null;
        $tenantId = $value['tenant_id'] ?? null;
        $version = $value['version'] ?? null;
        if (! is_string($mode) || ! is_int($version) || $version !== 1
            || ($tenantId !== null && ! is_string($tenantId))) {
            throw new TenantBoundaryViolation('Scheduled mail has an invalid persisted tenant envelope.');
        }
        $contextMode = TenantContextMode::tryFrom($mode)
            ?? throw new TenantBoundaryViolation('Scheduled mail has an unknown tenant context mode.');

        return new TenantJobEnvelope(new TenantContextSnapshot(
            $contextMode,
            $tenantId === null ? null : new TenantId($tenantId),
        ), $version);
    }
}
