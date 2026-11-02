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
use Nvl\MailNotifications\ValueObjects\NotifiableReference;
use Nvl\MailNotifications\ValueObjects\Recipient;
use Nvl\MailNotifications\ValueObjects\ScheduledMailReadQuery;
use Nvl\Support\Auth\CanonicalActorResolver;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Authorized, privacy-bounded native scheduled delivery reads.
 *
 * @api
 */
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
        private CanonicalActorResolver $actors,
    ) {}

    /**
     * Return bounded scheduled-intent projections through live ownership and host authorization checks.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(Authenticatable $actor, array $filters = []): array
    {
        $actor = $this->actors->resolve($actor);
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

    /**
     * Read one authorized scheduled intent while excluding protected payload and claim fields.
     *
     * @return array<string, mixed>
     */
    public function get(Authenticatable $actor, string $id): array
    {
        $actor = $this->actors->resolve($actor);
        $message = $this->boundary->query(MailModelRegistry::scheduledQuery(), 'mail.scheduled')
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

    /**
     * Aggregate scheduled state only within the caller's authorized read scope.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function statistics(Authenticatable $actor, array $filters = []): array
    {
        $actor = $this->actors->resolve($actor);
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

    /**
     * @param array<string, mixed> $filters */
    private function filter(array $filters): ScheduledMailReadQuery
    {
        $allowed = ['status', 'factoryAlias', 'notifiable', 'from', 'to', 'dueOnly', 'sort', 'direction', 'page', 'per_page'];
        if (array_diff(array_keys($filters), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown scheduled mail filter.');
        }

        $status = $filters['status'] ?? null;
        if ($status !== null && (! $status instanceof ScheduledMailStatus)) {
            throw new InvalidArgumentException('Invalid status mail filter.');
        }
        $factoryAlias = $filters['factoryAlias'] ?? null;
        if ($factoryAlias !== null && (! is_string($factoryAlias))) {
            throw new InvalidArgumentException('Invalid factoryAlias mail filter.');
        }
        $notifiable = $filters['notifiable'] ?? null;
        if ($notifiable !== null && (! $notifiable instanceof NotifiableReference)) {
            throw new InvalidArgumentException('Invalid notifiable mail filter.');
        }
        $from = $filters['from'] ?? null;
        if ($from !== null && (! $from instanceof CarbonImmutable)) {
            throw new InvalidArgumentException('Invalid from mail filter.');
        }
        $to = $filters['to'] ?? null;
        if ($to !== null && (! $to instanceof CarbonImmutable)) {
            throw new InvalidArgumentException('Invalid to mail filter.');
        }
        $dueOnly = $filters['dueOnly'] ?? false;
        if (! is_bool($dueOnly)) {
            throw new InvalidArgumentException('Invalid dueOnly mail filter.');
        }
        $sort = $filters['sort'] ?? 'scheduled_for';
        if (! is_string($sort)) {
            throw new InvalidArgumentException('Invalid sort mail filter.');
        }
        $direction = $filters['direction'] ?? 'asc';
        if (! is_string($direction)) {
            throw new InvalidArgumentException('Invalid direction mail filter.');
        }
        $page = $filters['page'] ?? 1;
        if (! is_int($page)) {
            throw new InvalidArgumentException('Invalid page mail filter.');
        }
        $perPage = $filters['per_page'] ?? 25;
        if (! is_int($perPage)) {
            throw new InvalidArgumentException('Invalid per_page mail filter.');
        }

        return new ScheduledMailReadQuery(
            status: $status,
            factoryAlias: $factoryAlias,
            notifiable: $notifiable,
            from: $from,
            to: $to,
            dueOnly: $dueOnly,
            sort: $sort,
            direction: $direction,
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @return array<string, mixed> */
    private function project(ScheduledMailMessage $message): array
    {
        $recipients = $message->getAttribute('to_recipients');
        $primary = is_array($recipients) ? ($recipients[0] ?? null) : null;
        $recipient = null;
        if ($primary !== null) {
            if (! is_array($primary)) {
                throw new InvalidArgumentException('Persisted scheduled mail primary recipient is invalid.');
            }
            $email = $primary['email'] ?? null;
            $name = $primary['name'] ?? null;
            if (! is_string($email) || ($name !== null && ! is_string($name))) {
                throw new InvalidArgumentException('Persisted scheduled mail primary recipient is invalid.');
            }
            $recipient = (new Recipient($email, $name))->toArray();
        }

        return [
            'id' => $message->id,
            'factory_alias' => $message->factory_alias,
            'payload_version' => $message->payload_version,
            'primary_recipient' => $recipient,
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
