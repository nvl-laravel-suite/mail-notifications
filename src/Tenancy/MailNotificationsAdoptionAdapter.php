<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Applies reviewed ownership to independent mail roots and derives provider events. */
final readonly class MailNotificationsAdoptionAdapter implements TenantAdoptionAdapter
{
    /** Create the package adopter. */
    public function __construct(private Migrator $migrator, private TenantAdoptionMappings $mappings) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['mail.notifications', 'mail.events', 'mail.scheduled'];
    }

    /** Install ownership expansion separately from activation. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->connection($plan);
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([
            dirname(__DIR__, 2).'/database/tenancy-migrations',
        ], ['force' => true]));
    }

    /** Assign reviewed notification and schedule roots, then derive each provider event. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->connection($plan);
        [$resource, $after] = str_contains((string) $cursor, '|')
            ? explode('|', (string) $cursor, 2)
            : ['mail.notifications', $cursor];
        $assignments = $this->mappings->assignments($plan, $resource, $after, $limit);
        $connection->transaction(function () use ($assignments, $connection, $resource): void {
            foreach ($assignments as $assignment) {
                $values = [
                    'tenant_id' => $assignment->tenantId->value,
                    'ownership_key' => 'tenant:'.$assignment->tenantId->value,
                ];
                $table = $resource === 'mail.notifications'
                    ? MailNotificationsTables::Notifications
                    : MailNotificationsTables::ScheduledMessages;
                if ($resource === 'mail.scheduled') {
                    $values['tenant_envelope'] = json_encode([
                        'mode' => 'tenant',
                        'tenant_id' => $assignment->tenantId->value,
                        'version' => 1,
                    ], JSON_THROW_ON_ERROR);
                }
                $connection->table($table)->where('id', $assignment->recordId)->update($values);
                if ($resource === 'mail.notifications') {
                    $connection->table(MailNotificationsTables::Events)
                        ->where('mail_notification_id', $assignment->recordId)
                        ->update(['tenant_id' => $assignment->tenantId->value]);
                }
            }
        });

        if ($assignments !== []) {
            return new TenantBackfillResult($resource.'|'.$assignments[array_key_last($assignments)]->recordId, count($assignments));
        }
        if ($resource === 'mail.notifications') {
            return new TenantBackfillResult('mail.scheduled|', 0);
        }

        return new TenantBackfillResult(null, 0);
    }

    /** Verify discriminator and inherited event consistency. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->connection($plan);
        $errors = [];
        foreach ([MailNotificationsTables::Notifications, MailNotificationsTables::ScheduledMessages] as $table) {
            foreach ($connection->table($table)->select(['id', 'tenant_id', 'ownership_key'])->orderBy('id')->cursor() as $row) {
                $tenant = is_string($row->tenant_id) ? $row->tenant_id : null;
                $expected = $tenant === null ? 'platform' : 'tenant:'.$tenant;
                if (! is_string($row->ownership_key) || ! hash_equals($expected, $row->ownership_key)) {
                    $errors[] = $table.'.ownership:'.(string) $row->id;
                }
            }
        }
        if ($connection->table(MailNotificationsTables::Events.' as event')
            ->join(MailNotificationsTables::Notifications.' as notification', 'notification.id', '=', 'event.mail_notification_id')
            ->where(function ($query): void {
                $query->whereColumn('event.tenant_id', '!=', 'notification.tenant_id')
                    ->orWhere(function ($query): void {
                        $query->whereNull('event.tenant_id')->whereNotNull('notification.tenant_id');
                    })->orWhere(function ($query): void {
                        $query->whereNotNull('event.tenant_id')->whereNull('notification.tenant_id');
                    });
            })->exists()) {
            $errors[] = 'mail.events.ownership';
        }

        return new TenantVerification($errors);
    }

    /** Refuse activation until ownership is exact. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        if (! $this->verify($plan)->passed()) {
            throw new TenantBoundaryViolation('Mail notification tenant ownership did not verify.');
        }
    }

    /** Resolve canonical package storage. */
    private function connection(TenantAdoptionPlan $plan): Connection
    {
        $connection = (new MailNotification)->setConnection($plan->connection)->getConnection();
        if ($connection->getName() !== $plan->connection) {
            throw new TenantBoundaryViolation('Mail adoption requires canonical package storage.');
        }

        return $connection;
    }
}
