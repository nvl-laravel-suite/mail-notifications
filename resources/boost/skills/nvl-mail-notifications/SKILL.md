---
name: nvl-mail-notifications
description: Implement, integrate, test, or review nvl/mail-notifications in Laravel 13. Use for opt-in mail tracking, lifecycle transitions, provider adapters, webhook normalization, protected storage, legacy adoption, authorized administrative reads, scheduled delivery, bounded retention, database invariants, and schema diagnostics.
---

# NVL Mail Notifications

Use this package to observe selected Laravel Mailables. Do not use it to replace
Laravel Mail or rewrite business recipients.

## Opt in deliberately

- Require `TrackableMessage` and use `TracksMailDelivery` only on Mailables that
  need operational tracking.
- Use `withoutMailTracking()` for a single delivery that must bypass tracking.
- Configure excluded Laravel mailers for transport-wide opt-out.
- Keep the global package switch available for incident response.
- Verify untracked Mailables still send without creating records.

## Preserve provider-neutral core

- Use `TrackingContext` for stable categories, notifiable references, and safe
  correlation metadata.
- Use `TrackingContext::withCorrelation()` only for identifiers needed directly
  by `MailTrackingStarted` listeners. It accepts at most 20 lowercase snake-case
  keys (64 characters), rejects keys containing `email`, `token`, `secret`,
  `password`, or `payload`, and allows only UTF-8 strings up to 255 characters,
  integers, booleans, or null. Nested values, objects, resources, and floats are
  rejected. The map survives `forNotifiable()`/`withMetadata()` clone order,
  persists under redacted `metadata.correlation`, and is passed directly on the
  event; arbitrary metadata is never copied to the event or reloaded for it.
- Prefer `notifiable_types` for direct host aliases and
  `extensions.notifiable_type_providers` for modular providers.
- Prefer configuration-first registration under `extensions.provider_adapters`
  and `extensions.message_id_resolvers`. Register operator-only remote managers
  separately under `extensions.webhook_managers`.
- Let one `ProviderAdapter` also implement `ProviderMessageIdResolver`,
  `WebhookSignatureVerifier`, and `WebhookEventNormalizer` when one integration
  owns all provider concerns.
- Use the public container tags only when registration must be dynamic.
- Verify and normalize webhooks through `WebhookSignatureVerifier` and
  `WebhookEventNormalizer` by passing the raw request through
  `WebhookProcessor`.
- Require real webhook requests to be `POST` with an allowlisted JSON content
  type, and enforce provider occurrence-time age/future-skew bounds after
  authentication.
- Keep unknown-event acknowledgement separate from unmatched tracked-message
  handling. Retry recent unmatched events for the configured grace period, then
  acknowledge genuinely untracked domain traffic.
- Correlate verified delivery events only by `correlation_id`, or by exact
  provider plus provider-message identity when correlation is absent. Never
  fall back to recipient, subject, category, or timing.
- Require custom `TrackingLifecycle` implementations to resolve exactly one
  tracked delivery before mutation and throw
  `AmbiguousDeliveryEventException` for multiple candidates. Acknowledge that
  condition once as `ambiguous_event`, observe `WebhookEventAmbiguous`, and
  repair the schema or lifecycle instead of retrying or guessing.
- Require custom `TrackingLifecycle` implementations to reconcile Laravel's
  terminal queued-Mailable callback through `queuedFailure()`. Use only its
  stable queue reference, never recipient, subject, timing, or latest-pending
  guesses; fence a missing fallback row with the queue reference as its primary
  and correlation identity.
- Use `RemoteWebhookManager` only through explicit sync/remove commands. Keep it
  disabled and unregistered by default, preview with `--dry-run`, require
  `--force` for drift updates, and require explicit `--all` for domain-wide
  deletion.
- For the built-in MailerSend manager, use Laravel's HTTP client only, require
  HTTPS/v2/bounded configuration with separate connection and total request
  timeouts, never make API calls at boot, and never print provider response
  bodies, bearer tokens, or generated signing secrets.
- Keep host routes, credentials, authorization, rate limiting, and provider SDK
  dependencies outside core.
- Keep provider SDK imports outside core.

## Keep host integration configurable

- Keep published configuration cache-safe: scalars, arrays, and class strings
  only.
- Use `services.tracking_lifecycle` and
  `services.sensitive_data_redactor` for deliberate contract replacements.
- Keep `services.sensitive_storage_transformer` as a cache-safe class string.
  Leave protected storage disabled until its transformer round trip passes the
  strict doctor.
- Use `providers.mailers` to map Laravel mailers to stable provider names and
  `providers.default` only as the fallback.
- Use `storage.connection` and `storage.tables` when the package must live
  outside the host's default database or table names.
- Require MySQL 8.0.16+, or MariaDB 10.3+ through Laravel's `mariadb` driver
  with session `check_constraint_checks` enabled. Keep the configured
  database/session timezone UTC.
- Use independent package, tracking, presentation, testing, webhook, and
  migration switches according to the operational boundary being disabled, and
  require every switch to be an actual boolean.
- Never let the package creator silently adopt an existing table pair. Require
  its exact migration-history record, or disable package migrations and make
  host ownership explicit.
- Keep every first-release schema requirement in the creator migrations while
  the package is unpublished. Queue identity, privacy markers, retention
  indexes, and exact status invariants must exist on a fresh install; do not
  introduce corrective migrations for unpublished schema drift.
- Make the earlier compatibility preflight accept only a completely fresh
  target or an exact creator-owned schema. Revalidate creator history, the
  configured notification/event pair, privacy-marker definitions, status
  allowlists, cascade linkage, and explicit index names.
- Keep package migration downs as intentional no-ops so framework rollback
  cannot erase mail history. Treat the package schema as forward-only; after a
  rollback removes migration-history rows, restore the exact baseline or move
  ownership to application forward migrations before another deploy.
- Treat migration compatibility as columns plus types, nullability, defaults,
  identities, ownership cascades, and operational indexes—not names alone.
- Require exact enum-value invariants for notification status, normalized
  provider-event type, and scheduled-message status. Use named checks on
  PostgreSQL, MySQL 8.0.16+, and supported MariaDB and paired insert/update
  triggers on SQLite; reject same-named ineffective or reversed predicates.
- Respect Laravel table prefixes and PostgreSQL schema-qualified table names
  when validating physical ownership cascades.
- Reject invalid configured extensions, unsafe privacy configuration, and
  oversized or provider-mismatched webhooks.

## Adopt legacy schemas through one reviewed manifest

- Publish `mail-notifications-adoption` and edit the version 1 manifest. Never
  infer table, column, status, notifiable, scheduled-factory, or foreign-key
  mappings during deployment.
- Run `nvl:mail-notifications:adopt <manifest> --stage` without `--apply`
  before package migrations when legacy tables occupy canonical names. Apply
  only after reviewing every rename and detached host foreign key.
- Run the command without `--stage` after package migrations. Dry-run first and
  require exact source counts, UUID identities, registered notifiable aliases,
  explicit status maps, and scheduled factory alias/version support.
- Allowlist support-safe metadata and normalized provider-event milestones.
  Never import bodies, raw provider responses, tokens, traces, scheduler claims,
  or legacy worker locks.
- Restore only declared host foreign keys after identity reconciliation. Keep
  `drop_sources=false` through the rollback window and treat applied cutovers as
  forward-only.

## Use package administrative read contracts

- Bind `MailNotificationReadAuthorization`; the default adapter denies reads
  until the host explicitly authorizes list, view, statistics, or suggestions.
- Use `MailNotificationReadQuery` and the package list/show/statistics/suggestion
  Actions instead of querying mutable package models in controllers.
- `GetMailNotificationStatisticsAction` returns top `mailers` and `categories`
  as `Nvl\MailNotifications\ValueObjects\MailNotificationAggregate` values
  with public readonly `key` and `count` fields from the same authorized
  filters. Its signature is `execute(Authenticatable $actor,
  MailNotificationReadQuery $filters): MailNotificationStatistics`. Each
  dimension uses one grouped query, orders count descending/key ascending,
  normalizes blank/null keys to `unknown`, and returns at most ten rows.
- Use `ListMailNotificationsForNotifiableAction` with a registered
  `NotifiableReference` for one subject's history, and
  `ShowMailNotificationByProviderMessageAction` with a registered
  `ProviderMessageId` for provider callbacks and status lookup.
- Keep search, dates, status, mailer/category filters, sorts, page sizes, and
  suggestion limits bounded by the package contracts and configuration.
- Bind `ScheduledMailReadAuthorization` separately and use the scheduled
  list/show/statistics Actions with `ScheduledMailReadQuery`; grant its list,
  view, and statistics abilities independently.
- Require the Doctor to report both effective read-authorization
  implementations. The built-in adapters with no callbacks are intentionally
  healthy but visibly fail closed; invalid non-null callbacks are unhealthy.
- Return only the package scheduled-mail value objects. The primary recipient
  display is intentionally one address/name pair; never expose payload,
  metadata, complete TO/CC/BCC envelopes, errors, claims, or locks.
- Return package read value objects. Do not add recipient arrays, metadata,
  webhook payloads, scheduled payloads, claims, or locks to administrative
  projections.
- Keep routes, controllers, rate limits, permissions, translations, and UI
  composition host-owned.

```php
use Illuminate\Mail\Mailable;
use Nvl\MailNotifications\Actions\GetMailNotificationStatisticsAction;
use Nvl\MailNotifications\Contracts\TrackableMessage;
use Nvl\MailNotifications\Events\MailTrackingStarted;
use Nvl\MailNotifications\Laravel\Concerns\TracksMailDelivery;
use Nvl\MailNotifications\ValueObjects\MailNotificationAggregate;
use Nvl\MailNotifications\ValueObjects\MailNotificationReadQuery;
use Nvl\MailNotifications\ValueObjects\TrackingContext;

$statistics = app(GetMailNotificationStatisticsAction::class)->execute(
    $actor,
    new MailNotificationReadQuery(from: $windowStart),
);
$topMailers = $statistics->mailers;
$topCategories = $statistics->categories;

final class ReminderMail extends Mailable implements TrackableMessage
{
    use TracksMailDelivery;

    public function __construct(public readonly string $occurrenceId) {}

    public function trackingContext(): TrackingContext
    {
        return TrackingContext::forCategory('domain.reminder')
            ->withCorrelation(['reminder_occurrence_id' => $this->occurrenceId]);
    }
}

final class LinkReminderAttempt
{
    public function handle(MailTrackingStarted $event): void
    {
        $occurrenceId = $event->correlation['reminder_occurrence_id'] ?? null;
    }
}

/** @var MailNotificationAggregate $aggregate */
$aggregate = $topMailers[0];
$key = $aggregate->key;
$count = $aggregate->count;
```

`TrackingContext::withMetadata()` clones persistent context metadata.
`$mailable->withTrackingMetadata()` comes from `TracksMailDelivery`; it is a
fluent, one-send Mailable override merged into the returned context immediately
before staging.
Neither method adds values to the event-safe correlation map; use
`TrackingContext::withCorrelation()` explicitly for that boundary.

## Protect delivery and data

- Keep fail-closed behavior before transport unless the host explicitly chooses
  fail-open.
- Require tracked mailers to resolve to `Illuminate\Mail\Mailer` or a subclass
  so final-message tracking stays at the Symfony transport boundary.
- Treat custom Symfony transports as supported, but handle decorators that only
  expose `Illuminate\Contracts\Mail\Mailer` through the failure policy. Move
  those decorators to the Symfony transport layer, exclude the mailer, or use
  fail-open delivery without a tracking row.
- Never throw a tracking update failure after provider acceptance in a way that
  can resend the message.
- When `MailTrackingFailed` carries a resolved provider message identity,
  repair the existing attempt through an idempotent lifecycle call; never
  resend the Mailable to repair tracking state.
- Store effective recipients after host interception.
- Treat `forNotifiable()` and `withTrackingMetadata()` as one-send overrides:
  preserve them through queue serialization, clear them after every actual send
  attempt, and reapply them before intentionally reusing a Mailable.
- Keep queue references separate from per-attempt correlations. Assign one
  before Laravel serializes a queued Mailable, clear it from the original after
  enqueue, and let the terminal failure hook no-op when a normal attempt already
  failed or reached provider acceptance.
- When a host Mailable defines `failed(?Throwable)`, require it to call
  `recordMailTrackingFailure($exception)`. Keep that helper best-effort so
  tracking synchronization never replaces the original queue failure.
- Build pre-send failure rows from envelope/address metadata only. Never render
  content, store exception messages or traces, or fail another worker's pending
  attempt selected only by queue-group ordering.
- Treat lifecycle status as transport-message-level; send separately when
  recipient-specific provider outcomes must remain independent.
- Dispatch fail-closed tracked mail after surrounding database transactions
  commit, or use an independent tracking connection.
- Dispatch package events after the exact configured storage transaction
  commits. Do not add Laravel's generic dispatch-after-commit marker to package
  events because an unrelated host connection must not delay them; storage
  rollback must emit nothing.
- Do not store rendered bodies, template variables, provider responses, or raw
  webhooks in core.
- Canonicalize configured and incoming metadata keys by lowercasing and
  removing separators/non-alphanumerics before sensitive-fragment matching, so
  snake, kebab, camel, and spaced variants receive the same redaction.
- Redact recursively and emit events with identifiers instead of content.
- Treat protected JSON storage as optional and opaque. Preserve plaintext
  legacy reads and version 1 envelopes, base64-wrap arbitrary transformer bytes
  in the version 2 JSON-safe envelope, and throw
  `UnreadableSensitiveDataException` when a marked value cannot be restored.
  Never fall back to returning ciphertext or malformed envelopes as plaintext.
- Keep current and previous transformer keys/profiles available for retained
  history. The packaged Laravel transformer uses the application encrypter and
  `APP_PREVIOUS_KEYS`; custom transformers must provide equivalent rotation
  semantics.
- Treat package Eloquent models as read/query projections for host integration,
  not arbitrary runtime write APIs. Route lifecycle, webhook, scheduler,
  anonymization, and pruning mutations through explicit package
  services/contracts.
- Route every internal sensitive-array write, including scheduled replacement,
  through the model cast/codec. Direct model or query-builder writes to status,
  payload, recipient, or metadata fields are unsupported because they bypass
  transitions, timestamps, events, protection, and audit.
- Remember that subject, sender, primary-recipient, notifiable, lifecycle, and
  provider-identity columns remain queryable scalars. Use broader database/disk
  encryption when those also require at-rest protection.
- Treat published Markdown and Blade overrides as host-owned customizations.

## Configure presentation

- Use the packaged tokenized Markdown components as the generic default.
- Keep default views free from host assets, URLs, translation keys, and copy.
- Configure brand values and header/footer visibility instead of hard-coding
  application identity.
- Preserve application paths before the package component path.
- Publish editable overrides with the `mail-notifications-mail-views` tag.
- Respect Laravel's selected Markdown theme, mailer, from address, and queue.
- Use the package-wide and presentation-specific switches independently.
- Prefer an existing `mail.testing` configuration for global safe-recipient
  interception and capture the resulting effective recipients.

## Separate provider submission from delivery

- Treat `ScheduleMailData::$scheduledFor` as the intended recipient delivery
  instant and optional `availableAt` as package claim/submission eligibility.
- Keep both caller inputs in UTC and reject initial availability later than
  intended delivery. Omit `availableAt` to default it to `scheduledFor`.
- When scheduling is enabled, require at least one registered versioned factory
  and both host-owned process/recovery commands to pass Doctor readiness.
- Use early availability only when the host factory maps
  `ScheduledMessageData::$scheduledFor` to the provider's real `sendAt` or
  equivalent option. Keep provider lead-time limits and validation host-owned.
- On reschedule, pass a new availability explicitly when preserving a provider
  lead time; omission resets availability to the new delivery instant.
- Replace both timing values atomically through `replacePending()`.
- Require scheduled payload and metadata arrays to be top-level string-keyed
  objects; reject list-shaped values before create or replacement persistence.
- Do not use `availableAt` as provider delivery intent: retry and recovery may
  move that operational eligibility beyond the original `scheduledFor`.
- When scheduled delivery also needs tracking, make the factory return an
  opted-in Mailable whose `TrackingContext` carries the scheduled notifiable,
  safe metadata, `scheduled_message_id`, and intended `scheduled_for`; apply a
  payload-owned locale with Laravel's native `locale()`.

## Prune explicitly and conservatively

- Keep retention database-only and host-invoked; do not auto-schedule pruning
  or call remote providers.
- Preview deterministic candidates with
  `nvl:mail-notifications:prune --dry-run`.
- Bound each data set with `retention.limit` or `--limit` and each query with
  `retention.batch_size`.
- Allowlist notification statuses and age them from `status_changed_at`;
  use `created_at` only when the lifecycle timestamp is null.
- Keep status-prefixed indexes aligned with both notification retention
  timestamps so current and legacy candidates remain bounded at scale.
- Keep scheduled-message retention separately opt-in and disabled by default so
  tracking-only hosts never need its table.
- When enabled, allow only sent, failed, or cancelled rows and age them from
  their matching terminal timestamp, falling back to `updated_at` only when
  that timestamp is null. Never prune pending or processing work.
- Require status-prefixed scheduled indexes for each terminal timestamp and the
  `updated_at` legacy fallback.
- Remove owned provider events transactionally with their selected
  notifications.
- Run the strict doctor and require `retention.configuration` to pass before
  scheduling real deletion.

## Anonymize as a separate stage

- Keep anonymization disabled by default and host-invoked. Preview bounded
  candidates with `nvl:mail-notifications:anonymize --dry-run`.
- Apply the configured or command limit independently to notifications,
  provider events, and terminal scheduled messages, then update in bounded
  batches.
- Clear recipient/content/notifiable data while preserving row, correlation,
  queue, provider-message, and provider-event identities needed for
  idempotency and late-webhook handling.
- Use nullable `redacted_at` markers and exact candidate indexes so repeated
  runs are honest and idempotent. Mark provider events independently from
  their notification.
- Never rehydrate anonymized metadata during acceptance, local failure, or
  provider-event reconciliation. Store a provider event arriving after parent
  anonymization without metadata and with its own redaction marker.
- Keep terminal scheduled-message anonymization separately opt-in. Never
  anonymize pending or processing work.
- Schedule this stage before pruning only when the host wants an anonymized
  intermediate retention window. Neither command invokes or schedules the
  other.

## Verify

Use the package-owned Eloquent model factories for tracking, provider-event,
and scheduled-message fixtures. Prefer their named lifecycle states over
assembling status and timestamp arrays in host tests; override only host-owned
references and payload fields needed by the scenario.

Test opt-in, instance opt-out, excluded mailers, global disablement, SMTP or
standard transport correlation, custom mailer decorators under fail-open and
fail-closed policy, queue serialization, pre-send terminal failure,
duplicate-callback fencing, duplicate-worker interleaving, host failure-hook
composition, nullable manual failure, monotonic transitions, duplicate and
stale provider events, recent/aged unmatched webhooks, POST/content-type and
provider timestamp guards, custom-lifecycle and broken-schema ambiguity,
remote webhook create/no-op/update/remove/dry-run flows with HTTP fakes,
retention/anonymization dry runs and terminal-row protection, protected storage
legacy/rotation/unreadable paths, database invariant tampering, and a real PostgreSQL
two-worker claim race synchronized at the locking query. Also race duplicate
queued-failure callbacks at the fallback insert and assert one row plus one of
each lifecycle event. PostgreSQL proofs must use a disposable database guarded
by the `nvl_mail_notifications_test_` name prefix, rebuild it explicitly before
each test, use committed setup outside ordinary test transactions, and skip
clearly on other databases or when optional process primitives are unavailable.
Also cover redaction, forward-only migration rollbacks, and
`nvl:mail-notifications:doctor`. Verify UTC microsecond persistence and
comparison behavior under a non-UTC host application timezone.
