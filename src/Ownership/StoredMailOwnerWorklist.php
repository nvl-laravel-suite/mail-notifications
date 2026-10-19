<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Ownership;

use Carbon\CarbonImmutable;
use Nvl\MailNotifications\Contracts\MailOwnerWorklist;
use Nvl\MailNotifications\Enums\MailOwnerOperation;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Projects only source-backed owner identities for the selected bounded mail maintenance purpose. @internal */
final readonly class StoredMailOwnerWorklist implements MailOwnerWorklist
{
    public function __construct(private OwnershipReadiness $readiness) {}

    /** @return iterable<OwnerReference> */
    public function owners(MailOwnerOperation $operation): iterable
    {
        $models = match ($operation) {
            MailOwnerOperation::ProcessScheduled, MailOwnerOperation::RecoverScheduled => ['mail.scheduled' => ScheduledMailMessage::class],
            default => ['mail.notifications' => MailNotification::class, 'mail.scheduled' => ScheduledMailMessage::class],
        };
        $seen = [];
        foreach ($models as $resource => $class) {
            $this->readiness->assertActive($resource);
            $model = new $class;
            $query = $model->getConnection()->table($model->getTable())->useWritePdo()->whereNotNull('owner_reference');
            if ($operation === MailOwnerOperation::ProcessScheduled) {
                $query->where('status', 'pending')->where('available_at', '<=', DatabaseTimestamp::format(CarbonImmutable::now('UTC')));
            } elseif ($operation === MailOwnerOperation::RecoverScheduled) {
                $query->where('status', 'processing')->where('locked_until', '<=', DatabaseTimestamp::format(CarbonImmutable::now('UTC')));
            }
            foreach ($query->select(['id', 'owner_scope_key', 'owner_reference'])->orderBy('id')->lazyById(100) as $row) {
                if (! is_string($row->owner_reference)) {
                    throw new OwnershipViolation('Stored mail requires complete canonical owner identity.');
                }
                $owner = OwnerReference::fromJson($row->owner_reference);
                if ($owner->scopeKey() !== $row->owner_scope_key) {
                    throw new OwnershipViolation('Stored mail owner and partition differ.');
                }
                if (! isset($seen[$owner->scopeKey()])) {
                    $seen[$owner->scopeKey()] = true;
                    yield $owner;
                }
            }
        }
    }
}
