<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\MailNotificationReadAbility;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\Models\MailNotificationEvent;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;
use Nvl\MailNotifications\ValueObjects\NotifiableReference;
use Nvl\MailNotifications\ValueObjects\ProviderMessageId;
use Nvl\Support\Auth\CanonicalActorResolver;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/**
 * Authorized, privacy-bounded native delivery history reads.
 *
 * @api
 */
final readonly class MailHistory
{
    /** @var list<string> */
    public const array COLUMNS = ['id', 'correlation_id', 'queue_reference', 'mailer', 'provider',
        'provider_message_id', 'status', 'message_category', 'subject', 'from_email', 'from_name',
        'primary_recipient_email', 'notifiable_type', 'notifiable_id', 'accepted_at', 'delivered_at',
        'failed_at', 'status_changed_at', 'provider_occurred_at', 'redacted_at', 'created_at', 'updated_at'];

    public function __construct(
        private MailNotificationReadAuthorization $authorization,
        private MailNotificationReadQueryBuilder $queries,
        private MailNotificationNotifiableTypeRegistry $notifiableTypes,
        private ProviderRegistry $providers,
        private OwnershipBoundary $boundary,
        private CanonicalActorResolver $actors,
    ) {}

    /**
     * Read one tracked record through live actor, ownership and host read-authorization checks.
     *
     * @return array<string, mixed>
     */
    public function get(Authenticatable $actor, string $id): array
    {
        $actor = $this->actors->resolve($actor);
        $notification = $this->boundary->query(MailModelRegistry::notificationsQuery(), 'mail.notifications')
            ->select(self::COLUMNS)->findOrFail($id);
        $this->authorization->authorize(MailNotificationReadAbility::View, $actor, $notification);
        $notification->setRelation('providerEvents', $notification->providerEvents()
            ->select(['id', 'mail_notification_id', 'provider', 'provider_event_id', 'provider_message_id',
                'normalized_type', 'occurred_at', 'processed_at', 'redacted_at'])
            ->orderBy('occurred_at')->orderBy('id')->get());

        return $this->project($notification);
    }

    /**
     * Resolve provider tracking identity through the same protected history read boundary.
     *
     * @return array<string, mixed>
     */
    public function getByProviderMessage(Authenticatable $actor, ProviderMessageId $messageId): array
    {
        $this->providers->resolve($messageId->provider);
        $notification = $this->boundary->query(MailModelRegistry::notificationsQuery(), 'mail.notifications')
            ->select('id')->where('provider', $messageId->provider)
            ->where('provider_message_id', $messageId->value)->firstOrFail();

        return $this->get($actor, $notification->id);
    }

    /**
     * Return bounded, deterministically ordered tracked history with protected fields omitted.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(Authenticatable $actor, array $filters = []): array
    {
        $actor = $this->actors->resolve($actor);
        $this->authorization->authorize(MailNotificationReadAbility::List, $actor);
        $query = $this->filter($filters);

        return $this->page($this->queries->build($query), $query);
    }

    /**
     * Read history for the explicit business reference after authorizing both the actor and notifiable access.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function forNotifiable(Authenticatable $actor, NotifiableReference $notifiable, array $filters = []): array
    {
        $actor = $this->actors->resolve($actor);
        $this->authorization->authorize(MailNotificationReadAbility::List, $actor);
        if ($this->notifiableTypes->resolve($notifiable->type) === null) {
            throw new DomainException(sprintf('Mail notification notifiable type [%s] is not registered.', $notifiable->type));
        }
        $query = $this->filter($filters);

        return $this->page($this->queries->build($query)
            ->where('notifiable_type', $notifiable->type)
            ->where('notifiable_id', $notifiable->identifier), $query);
    }

    /**
     * Aggregate only the tracked history visible through the caller's validated read scope.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function statistics(Authenticatable $actor, array $filters = []): array
    {
        $actor = $this->actors->resolve($actor);
        $this->authorization->authorize(MailNotificationReadAbility::Statistics, $actor);
        $query = $this->queries->build($this->filter($filters));
        $total = (clone $query)->count();
        $rawCounts = (clone $query)->toBase()->select('status')->selectRaw('count(*) as aggregate_count')
            ->groupBy('status')->pluck('aggregate_count', 'status')->all();
        $statuses = [];
        foreach (MailDeliveryStatus::cases() as $status) {
            $statuses[$status->value] = $this->count($rawCounts[$status->value] ?? 0);
        }
        $accepted = (clone $query)->whereNotNull('accepted_at')->count();
        $successful = array_sum(array_intersect_key($statuses, array_flip(MailNotificationReadQueryBuilder::successfulStatuses())));
        $failed = array_sum(array_intersect_key($statuses, array_flip(MailNotificationReadQueryBuilder::failureStatuses())));
        $recent = (clone $query)->latest('created_at')->orderByDesc('id')->limit(5)->get()
            ->map(fn (MailNotification $notification): array => $this->project($notification))->values()->all();

        return [
            'total' => $total,
            'statuses' => $statuses,
            'accepted' => $accepted,
            'successful' => $successful,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round($successful / $total * 100, 2) : 0.0,
            'failure_rate' => $total > 0 ? round($failed / $total * 100, 2) : 0.0,
            'recent' => $recent,
            'mailers' => $this->aggregate($query, 'mailer'),
            'categories' => $this->aggregate($query, 'message_category'),
        ];
    }

    /**
     * Return bounded safe history suggestions under the same live authorization predicates.
     *
     * @return list<array<string, mixed>>
     */
    public function suggest(
        Authenticatable $actor,
        string $search,
        ?MailDeliveryStatus $status = null,
        ?string $mailer = null,
        ?int $limit = null,
    ): array {
        $actor = $this->actors->resolve($actor);
        $this->authorization->authorize(MailNotificationReadAbility::Suggest, $actor);
        $search = trim($search);
        if ($search === '' || mb_strlen($search) > 160) {
            throw new InvalidArgumentException('Mail notification suggestions require between 1 and 160 search characters.');
        }
        if ($mailer !== null && mb_strlen(trim($mailer)) > 128) {
            throw new InvalidArgumentException('The mail notification mailer must not exceed 128 characters.');
        }
        $maximum = config('nvl-mail-notifications.management.suggestion_limit', 20);
        $maximum = is_int($maximum) && $maximum > 0 ? $maximum : 20;
        $term = "%{$search}%";
        $query = $this->boundary->query(MailModelRegistry::notificationsQuery(), 'mail.notifications')
            ->select(['id', 'subject', 'primary_recipient_email', 'status', 'created_at'])
            ->where(static function (Builder $query) use ($term): void {
                $query->where('subject', 'like', $term)
                    ->orWhere('primary_recipient_email', 'like', $term)
                    ->orWhere('provider_message_id', 'like', $term);
            });
        if ($status instanceof MailDeliveryStatus) {
            $query->where('status', $status->value);
        }
        if ($mailer !== null && trim($mailer) !== '') {
            $query->where('mailer', trim($mailer));
        }

        return array_values($query->latest('created_at')->orderByDesc('id')
            ->limit(max(1, min($limit ?? $maximum, $maximum)))->get()
            ->map(static fn (MailNotification $notification): array => [
                'id' => $notification->id,
                'subject' => $notification->subject,
                'primary_recipient_email' => $notification->primary_recipient_email,
                'status' => $notification->status->value,
                'created_at' => $notification->created_at->toISOString(),
            ])->values()->all());
    }

    /**
     * @param array<string, mixed> $filters */
    private function filter(array $filters): MailNotificationReadQuery
    {
        $allowed = ['search', 'status', 'mailer', 'messageCategory', 'from', 'to', 'acceptedOnly',
            'failedOnly', 'sort', 'direction', 'page', 'per_page'];
        if (array_diff(array_keys($filters), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown mail notification filter.');
        }

        $search = $filters['search'] ?? null;
        if ($search !== null && (! is_string($search))) {
            throw new InvalidArgumentException('Invalid search mail filter.');
        }
        $status = $filters['status'] ?? null;
        if ($status !== null && (! $status instanceof MailDeliveryStatus)) {
            throw new InvalidArgumentException('Invalid status mail filter.');
        }
        $mailer = $filters['mailer'] ?? null;
        if ($mailer !== null && (! is_string($mailer))) {
            throw new InvalidArgumentException('Invalid mailer mail filter.');
        }
        $messageCategory = $filters['messageCategory'] ?? null;
        if ($messageCategory !== null && (! is_string($messageCategory))) {
            throw new InvalidArgumentException('Invalid messageCategory mail filter.');
        }
        $from = $filters['from'] ?? null;
        if ($from !== null && (! $from instanceof CarbonImmutable)) {
            throw new InvalidArgumentException('Invalid from mail filter.');
        }
        $to = $filters['to'] ?? null;
        if ($to !== null && (! $to instanceof CarbonImmutable)) {
            throw new InvalidArgumentException('Invalid to mail filter.');
        }
        $acceptedOnly = $filters['acceptedOnly'] ?? false;
        if (! is_bool($acceptedOnly)) {
            throw new InvalidArgumentException('Invalid acceptedOnly mail filter.');
        }
        $failedOnly = $filters['failedOnly'] ?? false;
        if (! is_bool($failedOnly)) {
            throw new InvalidArgumentException('Invalid failedOnly mail filter.');
        }
        $sort = $filters['sort'] ?? 'created_at';
        if (! is_string($sort)) {
            throw new InvalidArgumentException('Invalid sort mail filter.');
        }
        $direction = $filters['direction'] ?? 'desc';
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

        return new MailNotificationReadQuery(
            search: $search,
            status: $status,
            mailer: $mailer,
            messageCategory: $messageCategory,
            from: $from,
            to: $to,
            acceptedOnly: $acceptedOnly,
            failedOnly: $failedOnly,
            sort: $sort,
            direction: $direction,
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @param Builder<MailNotification> $builder
     * @return array<string, mixed> */
    private function page(Builder $builder, MailNotificationReadQuery $filters): array
    {
        $maximum = config('nvl-mail-notifications.management.maximum_per_page', 100);
        $maximum = is_int($maximum) && $maximum > 0 ? $maximum : 100;
        $paginator = $builder->orderBy($filters->sort, $filters->direction)
            ->orderBy('id', $filters->direction)
            ->paginate(perPage: min($filters->perPage, $maximum), page: $filters->page);

        return [
            'items' => $paginator->getCollection()->map(fn (MailNotification $notification): array => $this->project($notification))->values()->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * @param Builder<MailNotification> $query
     * @param 'mailer'|'message_category' $column
     * @return list<array{key: string, count: int}> */
    private function aggregate(Builder $query, string $column): array
    {
        $selection = match ($column) {
            'mailer' => "COALESCE(NULLIF(TRIM(mailer), ''), 'unknown') as aggregate_key",
            'message_category' => "COALESCE(NULLIF(TRIM(message_category), ''), 'unknown') as aggregate_key",
        };
        $rows = (clone $query)->toBase()->select(DB::raw($selection))->selectRaw('count(*) as aggregate_count')
            ->groupBy('aggregate_key')->orderByDesc('aggregate_count')->orderBy('aggregate_key')->limit(10)->get();
        $result = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            $key = $values['aggregate_key'] ?? 'unknown';
            $result[] = ['key' => is_string($key) && $key !== '' ? $key : 'unknown',
                'count' => $this->count($values['aggregate_count'] ?? 0)];
        }

        return $result;
    }

    private function count(mixed $count): int
    {
        if (is_int($count)) {
            return max(0, $count);
        }
        $validated = is_string($count) ? filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;

        return is_int($validated) ? $validated : 0;
    }

    /**
     * @return array<string, mixed> */
    private function project(MailNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'correlation_id' => $notification->correlation_id,
            'queue_reference' => $notification->queue_reference,
            'mailer' => $notification->mailer,
            'provider' => $notification->provider,
            'provider_message_id' => $notification->provider_message_id,
            'status' => $notification->status->value,
            'message_category' => $notification->message_category,
            'subject' => $notification->subject,
            'from_email' => $notification->from_email,
            'from_name' => $notification->from_name,
            'primary_recipient_email' => $notification->primary_recipient_email,
            'notifiable_type' => $notification->notifiable_type,
            'notifiable_id' => $notification->notifiable_id,
            'accepted_at' => $notification->accepted_at?->toISOString(),
            'delivered_at' => $notification->delivered_at?->toISOString(),
            'failed_at' => $notification->failed_at?->toISOString(),
            'status_changed_at' => $notification->status_changed_at?->toISOString(),
            'provider_occurred_at' => $notification->provider_occurred_at?->toISOString(),
            'redacted_at' => $notification->redacted_at?->toISOString(),
            'created_at' => $notification->created_at->toISOString(),
            'updated_at' => $notification->updated_at->toISOString(),
            'events' => $notification->relationLoaded('providerEvents')
                ? $notification->providerEvents->map(static fn (MailNotificationEvent $event): array => [
                    'id' => $event->id,
                    'provider' => $event->provider,
                    'provider_event_id' => $event->provider_event_id,
                    'provider_message_id' => $event->provider_message_id,
                    'type' => $event->normalized_type->value,
                    'occurred_at' => $event->occurred_at->toISOString(),
                    'processed_at' => $event->processed_at->toISOString(),
                    'redacted_at' => $event->redacted_at?->toISOString(),
                ])->values()->all() : [],
        ];
    }
}
