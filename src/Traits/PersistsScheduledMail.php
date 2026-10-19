<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Traits;

use Illuminate\Database\Eloquent\Builder;
use Nvl\MailNotifications\Models\ScheduledMailMessage;

/** Shares private fixed-partition persistence among the package's scheduled-mail workflows. @internal */
trait PersistsScheduledMail
{
    /** Read only admitted owner schedules or the fixed global mail partition.
     * @return Builder<ScheduledMailMessage>
     */
    private function scheduledQuery(bool $maintenance = false): Builder
    {
        $this->tenantEnvelope->assertStorage('mail.scheduled');
        if (! $this->tenantEnvelope->globalScheduling()) {
            $query = $this->boundary->query(ScheduledMailMessage::query(), 'mail.scheduled');
            if ($this->tenantEnvelope->centralScheduling()) {
                $query->getQuery()->where('ownership_envelope->mode', 'central')->where($this->tenantEnvelope->centralProducerFilters());
            }

            return $query;
        }
        $model = new ScheduledMailMessage;

        $query = (new Builder($model->getConnection()->query()))->setModel($model)->where('owner_scope_key', 'central')->whereNull('owner_reference');
        if (! $maintenance) {
            $query->getQuery()->where('ownership_envelope->mode', 'unresolved');
        }

        return $query;
    }
}
