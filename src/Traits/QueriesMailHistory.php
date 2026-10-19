<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\MailNotifications\Exceptions\MailRetentionException;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\Models\ScheduledMailMessage;

/** Confines private retention operations to admitted history or fixed global delivery facts. @internal */
trait QueriesMailHistory
{
    /**
     * Select one history dataset without granting generic central model access.
     *
     * @template T of MailNotification|MailNotificationEvent|ScheduledMailMessage
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function historyQuery(Builder $query, string $resource): Builder
    {
        $model = $query->getModel();
        $global = $resource === 'mail.scheduled'
            ? $this->ownership->globalScheduling() : $this->ownership->globalTracking();
        if (! $global) {
            return $this->boundary->query($query, $resource);
        }

        return (new Builder($model->getConnection()->query()))->setModel($model)->where('owner_scope_key', 'central');
    }

    /**
     * Persist only the package's fixed privacy erasures; lifecycle and provider identities stay immutable.
     *
     * @param  MailNotification|MailNotificationEvent|ScheduledMailMessage  $model
     */
    private function saveRedaction(Model $model): bool
    {
        if (! $this->ownership->globalTracking()) {
            return $model->save();
        }
        $fields = match ($model::class) {
            MailNotification::class => ['subject', 'from_email', 'from_name', 'to_recipients', 'cc_recipients', 'bcc_recipients', 'primary_recipient_email', 'notifiable_type', 'notifiable_id', 'metadata', 'redacted_at', 'updated_at'],
            MailNotificationEvent::class => ['metadata', 'redacted_at', 'updated_at'],
            default => ['payload', 'to_recipients', 'cc_recipients', 'bcc_recipients', 'last_error', 'notifiable_type', 'notifiable_id', 'metadata', 'redacted_at', 'updated_at'],
        };
        $canonical = new ($model::class);
        $key = $model->getRawOriginal('id');
        if (! is_string($key) || ! $model->exists || $model->getRawOriginal('owner_scope_key') !== 'central'
            || $model->getConnection() !== $canonical->getConnection() || $model->getTable() !== $canonical->getTable()) {
            throw new MailRetentionException('Global redaction requires canonical persisted history.');
        }
        $model->updateTimestamps();
        $dirty = $model->getDirty();
        if (array_diff(array_keys($dirty), $fields) !== []) {
            throw new MailRetentionException('Mail redaction cannot change lifecycle or correlation facts.');
        }

        return $canonical->getConnection()->table($canonical->getTable())->where('id', $key)
            ->where('owner_scope_key', 'central')->update($dirty) === 1;
    }
}
