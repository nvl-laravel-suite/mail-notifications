<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Ownership;

use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Enums\MailOwnerOperation;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** Selects only protected central producer facts; the queue boundary must re-admit their real actor. @internal */
final readonly class ScheduledMailCentralWorklist
{
    public function __construct(private OwnershipReadiness $readiness) {}

    /** @return iterable<OwnershipJobEnvelope> */
    public function envelopes(MailOwnerOperation $operation): iterable
    {
        if ($operation !== MailOwnerOperation::ProcessScheduled) {
            return;
        }
        $this->readiness->assertActive('mail.scheduled');
        $model = new ScheduledMailMessage;
        $query = $model->getConnection()->table($model->getTable())->useWritePdo()->where('owner_scope_key', 'central')->whereNull('owner_reference')
            ->where('ownership_envelope->mode', 'central');
        $query->where('status', 'pending')->where('available_at', '<=', DatabaseTimestamp::format(CarbonImmutable::now('UTC')));
        $seen = [];
        foreach ($query->select(['id', 'ownership_envelope'])->orderBy('id')->lazyById(100) as $row) {
            if (! is_string($row->ownership_envelope)) {
                throw new OwnershipViolation('Central scheduled work requires a stored producer envelope.');
            }
            $wire = json_decode($row->ownership_envelope, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($wire)) {
                throw new OwnershipViolation('Central scheduled producer identity is malformed.');
            }
            $envelope = OwnershipJobEnvelope::fromArray($wire);
            if ($envelope->context()->mode !== OwnershipContextMode::Central) {
                throw new OwnershipViolation('Ordinary global work cannot promote itself into central admission.');
            }
            $key = hash('sha256', json_encode($envelope->toArray(), JSON_THROW_ON_ERROR));
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                yield $envelope;
            }
        }
    }
}
