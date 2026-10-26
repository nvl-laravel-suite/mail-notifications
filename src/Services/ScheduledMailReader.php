<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\ScheduledMailReadAuthorization;
use Nvl\MailNotifications\Enums\ScheduledMailReadAbility;
use Nvl\MailNotifications\Enums\ScheduledMailStatus;
use Nvl\MailNotifications\Models\ScheduledMailMessage;
use Nvl\MailNotifications\Support\DatabaseTimestamp;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduledMailReadQuery;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/** Authorized, privacy-bounded native scheduled delivery reads. */
final readonly class ScheduledMailReader
{
    /** @var list<string> */
    public const array LIST_COLUMNS = ['id', 'factory_alias', 'payload_version', 'to_recipients', 'status',
        'scheduled_for', 'available_at', 'attempts', 'max_attempts', 'sent_at', 'failed_at',
        'cancelled_at', 'created_at'];

    /** @var list<string> */
    public const array DETAIL_COLUMNS = [...self::LIST_COLUMNS, 'last_attempt_at', 'notifiable_type',
        'notifiable_id', 'redacted_at', 'updated_at'];

    public function __construct(
        private ScheduledMailReadAuthorization $authorization,
        private ScheduledMailReadQueryBuilder $queries,
        private OwnershipBoundary $boundary,
    ) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginate(Authenticatable $actor, array $filters = []): array
    {
        $this->authorization->authorize(ScheduledMailReadAbility::List, $actor);
        $query = $this->filter($filters);
        $maximum = config('nvl-mail-notifications.management.scheduled_maximum_per_page', 100);
        $maximum = is_int($maximum) && $maximum > 0 ? $maximum : 100;
        $paginator = $this->queries->build($query)->select(self::LIST_COLUMNS)
            ->orderBy($query->sort, $query->direction)->orderBy('id', $query->direction)
            ->paginate(perPage: min($query->perPage, $maximum), page: $query->page);

        return [
            'items' => $paginator->getCollection()->map(fn (ScheduledMailMessage $message): array => $this->project($message))->values()->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /** @return array<string, mixed> */
    public function get(Authenticatable $actor, string $id): array
    {
        $message = $this->boundary->query(ScheduledMailMessage::query(), 'mail.scheduled')
            ->select(self::DETAIL_COLUMNS)->findOrFail($id);
        $this->authorization->authorize(ScheduledMailReadAbility::View, $actor, $message);

        return [
            ...$this->project($message),
            'last_attempt_at' => $message->last_attempt_at?->toISOString(),
            'notifiable_type' => $message->notifiable_type,
            'notifiable_id' => $message->notifiable_id,
            'redacted_at' => $message->redacted_at?->toISOString(),
            'updated_at' => $message->updated_at->toISOString(),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function statistics(Authenticatable $actor, array $filters = []): array
    {
        $this->authorization->authorize(ScheduledMailReadAbility::Statistics, $actor);
        $query = $this->queries->build($this->filter($filters));
        $total = (clone $query)->count();
        $rawCounts = (clone $query)->toBase()->select('status')->selectRaw('count(*) as aggregate_count')
            ->groupBy('status')->pluck('aggregate_count', 'status')->all();
        $statuses = [];
        foreach (ScheduledMailStatus::cases() as $status) {
            $count = $rawCounts[$status->value] ?? 0;
            $validated = is_string($count) ? filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
            $statuses[$status->value] = is_int($count) ? $count : (is_int($validated) ? $validated : 0);
        }
        $due = (clone $query)->where('status', ScheduledMailStatus::Pending->value)
            ->where('available_at', '<=', DatabaseTimestamp::format(CarbonImmutable::now('UTC')))->count();
        $recent = (clone $query)->select(self::LIST_COLUMNS)->latest('created_at')
            ->orderByDesc('id')->limit(5)->get()
            ->map(fn (ScheduledMailMessage $message): array => $this->project($message))->values()->all();

        return ['total' => $total, 'statuses' => $statuses, 'due' => $due, 'recent' => $recent];
    }

    /** @param array<string, mixed> $filters */
    private function filter(array $filters): ScheduledMailReadQuery
    {
        $allowed = ['status', 'factoryAlias', 'notifiable', 'from', 'to', 'dueOnly', 'sort', 'direction', 'page', 'perPage'];
        if (array_diff(array_keys($filters), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown scheduled mail filter.');
        }

        return new ScheduledMailReadQuery(
            status: $filters['status'] ?? null,
            factoryAlias: $filters['factoryAlias'] ?? null,
            notifiable: $filters['notifiable'] ?? null,
            from: $filters['from'] ?? null,
            to: $filters['to'] ?? null,
            dueOnly: $filters['dueOnly'] ?? false,
            sort: $filters['sort'] ?? 'scheduled_for',
            direction: $filters['direction'] ?? 'asc',
            page: $filters['page'] ?? 1,
            perPage: $filters['perPage'] ?? 25,
        );
    }

    /** @return array<string, mixed> */
    private function project(ScheduledMailMessage $message): array
    {
        $recipients = $message->getAttribute('to_recipients');
        $primary = is_array($recipients) ? ($recipients[0] ?? null) : null;
        if ($primary !== null && (! is_array($primary) || ! isset($primary['email'])
            || ! is_string($primary['email']) || (isset($primary['name']) && ! is_string($primary['name'])))) {
            throw new InvalidArgumentException('Persisted scheduled mail primary recipient is invalid.');
        }

        return [
            'id' => $message->id,
            'factory_alias' => $message->factory_alias,
            'payload_version' => $message->payload_version,
            'primary_recipient' => is_array($primary) ? (new Recipient($primary['email'], $primary['name'] ?? null))->toArray() : null,
            'status' => $message->status->value,
            'scheduled_for' => $message->scheduled_for->toISOString(),
            'available_at' => $message->available_at->toISOString(),
            'attempts' => $message->attempts,
            'max_attempts' => $message->max_attempts,
            'sent_at' => $message->sent_at?->toISOString(),
            'failed_at' => $message->failed_at?->toISOString(),
            'cancelled_at' => $message->cancelled_at?->toISOString(),
            'created_at' => $message->created_at->toISOString(),
        ];
    }
}
