<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Ownership;

use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Requires live producer and external-correlation constraints before native mail effects. @internal */
final readonly class MailIntegrityGuard
{
    /** Retain neutral contracts so ordinary Mail never requires the optional ownership runtime. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipReadiness $readiness, private OwnershipConstraintInspector $constraints) {}

    /** Verify the selected native resource and its immutable external authority. */
    public function assertReady(string $resource): void
    {
        if ($this->resources->get($resource)->ownership->strategy === 'unscoped') {
            return;
        }
        $this->readiness->assertActive($resource);
        if ($resource === 'mail.scheduled') {
            $model = new ScheduledMailMessage;
            $purpose = 'freeze:'.hash('sha256', json_encode([['ownership_envelope'], 'status', [], [], false], JSON_THROW_ON_ERROR));
        } else {
            $model = new MailNotification;
            $purpose = 'assigned:'.hash('sha256', json_encode(['provider', 'provider_message_id'], JSON_THROW_ON_ERROR));
        }
        $this->constraints->assertPresent($model->getConnection(), $model->getTable(), 'update', $purpose);
    }
}
