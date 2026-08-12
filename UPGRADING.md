# Upgrading NVL Mail Notifications

## Canonical configuration namespace

Package webhook and MailerSend readiness reads only:

- `config('mail-notifications.webhooks.enabled')`;
- `config('mail-notifications.providers.mailersend.signing_secret')`;
- `config('mail-notifications.providers.mailersend.management.enabled')`.

Never place these values under the predecessor `mailnotifications.*` namespace.
A host may retain that older namespace for unrelated reminder settings, but it
does not configure this package and strict readiness will not inspect it.

## Upgrading to 1.0

Version 1.0 introduces the standalone provider-neutral package.

1. Install the package and run its migrations in an isolated environment.
2. Keep existing application Mailables, translations, and mail views in the
   host.
3. Implement the package tracking contract and concern only on messages that
   should be tracked.
4. Configure excluded mailers before enabling tracking in production.
5. Register stable notifiable aliases instead of persisting model class names.
6. Add provider adapters separately and verify signed webhook normalization.
7. Run `php artisan nvl:mail-notifications:doctor --strict --format=json`.
8. Move schema ownership only after the host confirms there is one migration
   owner.
9. Dispatch fail-closed tracked mail after the host transaction commits, or use
   an independent tracking connection.
10. Move custom `Mailer` contract decorators to the Symfony transport layer
    when they need tracking. Otherwise exclude that mailer or choose
    `fail_open`; `fail_closed` now rejects tracked delivery before a hidden
    transport is invoked.
11. Merge the new `retention` configuration into published configuration,
    preview candidates with `nvl:mail-notifications:prune --dry-run`, and let
    the host choose whether and when to schedule real pruning.
12. Install the current creator migrations, which already include queue
    identity, status invariants, `redacted_at`, and their exact indexes, before
    enabling tracking or anonymization.
13. Merge the disabled sensitive-storage and anonymization defaults into
    published configuration, then run the strict doctor.
14. Keep the current and previous sensitive-storage keys or protection profiles
    available for as long as protected history must remain readable.
15. Confirm the storage database can enforce exact status allowlists: MySQL
    8.0.16+, or MariaDB 10.3+ through Laravel's `mariadb` driver with session
    `check_constraint_checks` enabled. Keep the database/session timezone UTC.

### Adopting provider submission lead time

`ScheduleMailData` accepts an optional named `availableAt` argument in addition
to `scheduledFor`. Existing calls remain unchanged: omitting it stores
`available_at` equal to `scheduled_for`. Both inputs are normalized to UTC, and
caller-supplied availability later than intended delivery is rejected.

Use `scheduledFor` only for the intended recipient delivery instant. Use an
earlier `availableAt` only when the registered host
`ScheduledMessageFactory` maps `ScheduledMessageData::$scheduledFor` to the
provider's real deferred-delivery `sendAt` or equivalent option. Provider
limits remain host-owned. For example, a legacy MailerSend integration that
submits at most three days early may pass `$scheduledFor->subDays(3)` as
`availableAt`, but the package does not assume or enforce that provider-specific
window.

`ScheduledMailScheduler::reschedule()` now accepts the same optional third
argument. Omitting it deliberately resets submission availability to the new
delivery instant. `replacePending()` replaces both timing values from the new
`ScheduleMailData`. Existing schemas need no new column: this contract gives
the existing `available_at` column its explicit initial-submission meaning,
while retry and recovery logic may still move it later for another attempt.

### Adopting database retention

Tracked-notification pruning is configured through
`retention.notifications`, `retention.batch_size`, and `retention.limit`.
Eligibility uses the lifecycle `status_changed_at` timestamp, falling back to
`created_at` only for legacy rows where it is null. Provider events owned by a
selected notification are removed in the same transaction.

Fresh package migrations include `(status, status_changed_at)` for the normal
notification retention path while retaining `(status, created_at)` for legacy
rows. Host-owned schemas must include equivalent indexes. Recreate any earlier
unpublished development snapshot from the current creators; the package doctor
reports either missing lookup.

Scheduled-message pruning is independent and defaults to disabled. Enable
`retention.scheduled_messages.enabled` only in hosts that installed the
scheduled-mail schema. When enabled, configure only `sent`, `failed`, and
`cancelled`; pending and processing rows cannot be pruned. Their age is based
on the matching terminal timestamp, with `updated_at` used only when that
legacy timestamp is null. Add status-prefixed indexes for `sent_at`,
`failed_at`, `cancelled_at`, and the `updated_at` fallback when the host owns an
existing scheduled-mail table.

The package never schedules pruning automatically. Validate a deployment and
preview its bounded candidates before adding the command to the host scheduler:

```bash
php artisan nvl:mail-notifications:doctor --strict --format=json
php artisan nvl:mail-notifications:prune --dry-run
```

### Adopting status invariants and privacy markers

This package is unpublished before 1.0, so queue identity, status invariants,
and privacy markers are part of the original creator migrations rather than an
additive repair chain. `2026_07_29_000000` creates complete notification and
provider-event storage. `2026_07_30_000100` creates complete scheduled-message
storage. Fresh installs therefore receive `queue_reference`, nullable
`redacted_at` markers, their exact candidate indexes, and database allowlists
for `mail_notifications.status`,
`mail_notification_events.normalized_type`, and
`scheduled_mail_messages.status`.

PostgreSQL, MySQL 8.0.16+, and MariaDB 10.3+ use named `CHECK` constraints.
MariaDB must use Laravel's `mariadb` connection driver and keep the session
`check_constraint_checks` variable enabled. SQLite uses paired named `INSERT`
and `UPDATE` triggers. The preflight and creators reject an unsupported or
unenforced database before mutating schema. If a non-production environment
ran an earlier unpublished migration snapshot, recreate that schema from the
current creators instead of retaining or replaying removed corrective
migrations. Creator `down()` methods remain intentional no-ops.

Laravel connection prefixes and PostgreSQL schema-qualified table names are
supported and are part of physical foreign-key ownership inspection. Keep
those settings stable after migration. Package services and model timestamps
normalize to UTC with microsecond precision regardless of the host application
timezone; the database/session timezone must remain UTC.

Hosts with package migrations disabled must reproduce the documented
notification/event column, index, and invariant names and semantics in forward
application migrations. They must do the same for scheduled-message storage
when scheduling, scheduled retention, or scheduled anonymization is enabled.
When importing a host-owned legacy schema, repair invalid statuses before
installing equivalent checks, keep the configured table names stable, and
require this command to pass before deploying workers:

```bash
php artisan nvl:mail-notifications:doctor --strict --format=json
```

### Adopting sensitive storage and anonymization

Protected sensitive-array storage is disabled by default. A safe rollout is:

1. Install the complete current creator schema, or its host-owned exact
   equivalent, while protection remains disabled. Legacy plaintext arrays
   continue to read normally.
2. Configure a `SensitiveDataTransformer`; the packaged
   `LaravelEncrypterSensitiveDataTransformer` uses Laravel's current key and
   `APP_PREVIOUS_KEYS`.
3. Run the strict doctor so the transformer performs a protected in-memory
   round trip.
4. Enable `privacy.sensitive_storage.enabled`, rebuild configuration cache, and
   restart every web and queue worker together.

New values are protected after enablement. Version 2 envelopes base64-encode
arbitrary transformer bytes for JSON-safe storage; version 1 envelopes remain
readable. Legacy rows remain plaintext until they are deliberately rewritten
through package lifecycle or scheduler services; the package does not silently
bulk-rewrite history. Database JSON queries cannot inspect protected arrays.
Scalar subject, sender, primary-recipient, notifiable, lifecycle, and
provider-identity columns remain queryable and need database/disk encryption
when the host requires broader at-rest protection.

Do not disable the transformer, remove a previous key, or roll back to code
that does not understand the versioned envelope while protected rows remain.
Marked ciphertext that cannot be restored throws
`UnreadableSensitiveDataException`; it is never returned as plaintext. Restore
the compatible code, transformer, and keys or recover the rows from backup.

History anonymization is independent from encryption and deletion. Keep
`retention.anonymization.enabled` false until its ages, status allowlists,
batch size, and independent data-set limit are reviewed. Preview it explicitly:

```bash
php artisan nvl:mail-notifications:anonymize --dry-run
```

The command preserves row, correlation, queue, provider-message, and
provider-event identities while clearing identifying content and marking each
notification, provider event, or terminal scheduled message with
`redacted_at`. Schedule anonymization before pruning when the application
requires an anonymized intermediate retention window. Pruning does not require
or invoke it automatically.

Acceptance, local failure reconciliation, and exact provider-event retries do
not rehydrate anonymized metadata. Provider events arriving after their parent
notification was anonymized are stored without metadata and with their own
redaction marker, while immutable identity conflicts remain errors.

## Adopting a legacy tracker

Suite 1.0.2 adds a first-party, dry-run-first adoption boundary. Publish the
versioned manifest, replace every placeholder with reviewed source facts, and
record exact counts before changing schema:

```bash
php artisan vendor:publish --tag=mail-notifications-adoption
php artisan nvl:mail-notifications:adopt mail-notifications.adoption.json --stage
php artisan nvl:mail-notifications:adopt mail-notifications.adoption.json --stage --apply
php artisan migrate
php artisan nvl:mail-notifications:adopt mail-notifications.adoption.json
php artisan nvl:mail-notifications:adopt mail-notifications.adoption.json --apply
```

`--stage` is the pre-migration phase for incompatible tables occupying package
names. It validates every source/destination pair first, detaches only
manifest-declared host foreign keys, and renames the tables. After package
migrations create the canonical schema, omit `--stage` to validate and import.
Import refuses unknown statuses and notifiable types, unregistered scheduled
factory aliases or payload versions, invalid UUID identities, unreconciled host
references, count drift, target identity collisions, and enabled protected
storage. It creates normalized provider-event milestones, imports only
allowlisted metadata, never copies scheduler claims or locks, and restores
declared host foreign keys after exact reconciliation.

Set `drop_sources` only after the rollback window closes. An applied cutover is
forward-only: stop writers, inspect the report and database state, and continue
with an operator-reviewed forward migration instead of renaming tables back
while package delivery is enabled.

The package migration dated `2026_07_28` is a read-only preflight. Its earlier
timestamp deliberately sorts it before the first-release `2026_07_29` table
creator, so an incompatible configured legacy table stops the migration before
the package can create its companion table. Its rollback is a no-op because it
never owns schema.

The preflight validates portable column types, nullability, declared lengths,
the pending status default, exact privacy-marker definitions, primary and unique
identities, exact status allowlists, provider-event cascade ownership, and
named operational indexes. Matching column names alone are not sufficient.
Even a structurally compatible pre-existing pair is rejected when the exact
package creator migration is not recorded: future package schema evolution
requires one explicit owner and one authoritative migration history. Disable
package migrations and baseline host ownership intentionally, or select fresh
table names.

A fresh target with neither table remains a no-op so the following creator can
run. A notification-only schema is rejected even while the creator is pending:
the preflight cannot prove that the table came from an interrupted package
creation rather than a host-owned schema, and allowing the creator to continue
would create ambiguous single-writer ownership. An event-only schema is also
rejected. If the creator is already recorded but either owned table is missing,
restore the owned schema instead of expecting the recorded creator to run
again.

Choose one schema owner before installing in production:

1. Prefer fresh package-owned tables. Configure
   `MAIL_NOTIFICATIONS_DB_CONNECTION`, `MAIL_NOTIFICATIONS_TABLE`, and
   `MAIL_NOTIFICATION_EVENTS_TABLE` with unused names, run the package
   migrations, then import only the safe history described below.
2. If the host must own or adopt legacy tables, set
   `mail-notifications.migrations.enabled` to `false` and create a host-owned
   migration with the complete package schema, constraints, and indexes. Do not
   point the package at a similarly named legacy table and rely on
   `hasTable()` to adopt it.

For a legacy import, map fields deliberately:

- Map `driver` to `mailer`; keep the provider identity separate in `provider`
  and preserve `provider_message_id`.
- Generate a unique `correlation_id` for each historical attempt and map the
  legacy mail type or template name to a stable `message_category`.
- Map `pending` to `pending`, `sent` to `accepted`, `failed` to `failed`,
  `delivered` to `delivered`, `opened` to `opened`, `clicked` to `clicked`,
  `bounced` to `bounced`, `spam` to `complained`, and `unsubscribed` to
  `unsubscribed`.
- Map `sent_at` to `accepted_at`; preserve `delivered_at` and `failed_at`.
  Set `status_changed_at` and `provider_occurred_at` from the most authoritative
  legacy provider timestamp. Represent opened, clicked, bounced, complaint, and
  unsubscribe history as normalized event rows rather than inventing columns on
  the current-state table.
- Replace persisted fully qualified `notifiable_type` class names with registered,
  stable aliases before importing `notifiable_id`.

Never replay an accepted message to reconstruct history. Import database state
only. Exclude rendered HTML and text bodies, template variables, provider
responses, raw webhook payloads, exception traces, credentials, tokens, and
other sensitive delivery data. Keep only the minimum redacted metadata needed
for support and audit.

### Replacing a legacy MailNotifications module

This is a controlled application migration, not a namespace-only Composer
swap. The package replaces the module's portable write-side infrastructure and
provides privacy-bounded read Actions, while application delivery endpoints,
controllers, and presentation stay in the host or another application-owned
integration layer.

| Legacy module surface | Replacement |
| --- | --- |
| `TrackedMailable` and `TracksMailNotifications` | Package `TrackableMessage` plus `TracksMailDelivery` |
| `forNotifiable()` and `withTrackingMetadata()` | Same fluent intent; package values are one-delivery state and clear after every real send attempt |
| Legacy queued-Mailable `failed()` tracking | Built into `TracksMailDelivery`; a host `failed(?Throwable)` hook must call `recordMailTrackingFailure()` |
| `setLocale()` | Laravel's native Mailable `locale()`; notifiable fallback remains host policy |
| `getMailNotification()` | Use `ShowMailNotificationAction` with explicit read authorization; a sent Mailable does not retain an Eloquent tracking model |
| Module `MailTrackable` and `HandlesNotifications` | Package `MailTrackable` supplies only stable alias/identifier; use package read Actions for bounded delivery statistics and queries, while relations and timelines remain host-owned |
| `ProvidesMailerSendConfig` | Keep or relocate this transport/template contract in the host; the package does not own message content or the MailerSend sending driver |
| `MailNotificationStatusChanged` | `MailDeliveryStatusChanged` carries safe identifiers and enums; use an authorized package read Action when a host projection needs more data |
| `sent` / `spam` status | Map to package `accepted` / `complained`; package also distinguishes `delayed` and `rejected` |
| Register/deregister webhook commands | `nvl:mail-notifications:webhooks:sync` / `webhooks:remove`, with dry-run and explicit force/all safeguards |
| Reflection-based scheduled Mailable construction | Registered `ScheduledMessageFactory` alias plus payload version |
| Scheduled processing command | Package process and stale-claim recovery commands |
| Module status-check query/API | Use package list/show/statistics/suggestion read Actions behind a host `MailNotificationReadAuthorization`; keep controllers and exact provider-status endpoints host-owned when needed |
| Manual status updates or history deletion | Retire direct model mutation; use package lifecycle services and bounded anonymize/prune commands |
| Plain JSON payload/recipient history | Optional package sensitive-array transformer plus a separate bounded anonymization stage |
| Controllers, policies, permissions, translations, admin UI, and activity timeline | Keep host-owned; consume package privacy-bounded read DTOs, route every mutation through package services/contracts, and react to package events |

Every migrated tracked Mailable must implement `trackingContext()`. Preserve
stable categories rather than class names. Existing host models may keep their
module relationships temporarily, but new package writes must use registered
notifiable aliases and there must be only one tracking lifecycle writer.

Package events are deferred against the exact configured storage transaction,
not an unrelated open host connection. Event classes intentionally do not use
Laravel's generic cross-connection dispatch-after-commit marker; regular host
listeners observe committed package state, and a storage rollback emits no
package event. Listeners that opt into Laravel's own after-commit handling add
that separate host policy themselves.

Package Eloquent models are read/query projections for host integrations, not
arbitrary write APIs. Route tracking and webhook lifecycle changes through
`TrackingLifecycle` and `WebhookProcessor`, and scheduled-message changes
through `ScheduledMailScheduler` and the processing/recovery services. Retire
legacy controller or model code that directly updates status or deletes rows:
those writes bypass monotonic transitions, lifecycle timestamps, events,
queue/provider reconciliation, and the audit trail.

Install the complete creator schema before enabling migrated queue workers.
Hosts with package migrations disabled must include the nullable UUID
`queue_reference` column and exact
`mail_notifications_queue_created_index` on
`queue_reference, created_at` in their host-owned baseline. Do not reuse
`correlation_id`: every real send attempt keeps its own identity, while the
queue reference groups terminal callback reconciliation across Laravel's
serialized copies.

The compatibility preflight revalidates the exact creator migration record,
the currently configured notification/event table pair, their complete column
and invariant definitions, cascade ownership, and explicit package index names.
Keep table-name configuration stable. If the host owns differently named or
adopted tables, disable package migrations and reproduce the complete current
schema in application-owned forward migrations.

All package migration `down()` methods are forward-only no-ops. Framework
rollback therefore preserves notification, provider-event, scheduled-message,
queue-reference, status-invariant, and privacy-marker schema, but Laravel
removes their migration-history rows.
After an accidental rollback, restore those exact history records from a
deployment backup after schema verification, or disable package migrations and
take ownership through application forward migrations before the next deploy.
Do not treat rollback as package uninstallation.

Custom `TrackingLifecycle` replacements must implement `queuedFailure()`. The
operation must use the queue reference, no-op when a normal attempt is already
terminal or provider-accepted, never select an unrelated pending attempt by
ordering, and fence fallback creation with the queue reference as both row ID
and correlation ID.

When a scheduled message should create a tracking row, its host factory must
compose the scheduled data deliberately. A typical factory passes a context
like this into its opted-in Mailable:

```php
use Nvl\MailNotifications\ValueObjects\ScheduledMessageData;
use Nvl\MailNotifications\ValueObjects\TrackingContext;

/** @var ScheduledMessageData $message */
$context = new TrackingContext(
    category: 'receipt',
    notifiable: $message->notifiable,
    metadata: array_replace($message->metadata, [
        'scheduled_message_id' => $message->id,
        'scheduled_for' => $message->scheduledFor->toIso8601String(),
    ]),
);
```

The factory also owns Laravel `locale()` selection and, when `availableAt` is
earlier than `scheduledFor`, the provider driver's real deferred-delivery
`sendAt` option. The processor makes persisted TO/CC/BCC authoritative and
will replace recipients declared by the factory or Mailable.

Map legacy `scheduled_emails` rows explicitly:

- Map `template_type`, `template_id`, and safe `email_data` into a registered
  factory alias, positive payload version, and factory-validated payload. Never
  persist a Mailable class name or use reflection to restore one.
- Normalize `recipients` into package `ScheduledRecipients`.
- Map `schedulable_type` to a registered stable notifiable alias and
  `schedulable_id` to its string identifier; do not copy a PHP morph class.
- Keep `scheduled_for` as intended delivery. Set initial `available_at` to the
  same instant for ordinary local delivery, or to a provider-supported earlier
  submission instant when the factory applies the real provider `sendAt`.
- Pending maps to pending. Sent, failed, and cancelled rows may be imported as
  read-only terminal history with their matching timestamps. A processing row
  is ambiguous and must be drained or reconciled before import.
- Preserve attempts and the max-attempt ceiling only in a controlled host
  migration. Never copy `locked_until`, a legacy worker lock, or a package
  `claim_token`; imported pending work starts unclaimed.
- Do not copy raw `failure_reason` text. Omit it or map a bounded safe code.
- The legacy `mail_notification_id` is not a package scheduling foreign key.
  Preserve historical linkage in a host projection if needed; new tracked
  scheduled delivery links through safe `scheduled_message_id` metadata.

Use this cutover order:

1. Deploy package code, fresh package-owned tables, aliases, adapters, and
   factories with package tracking and scheduling still disabled.
2. Prove every legacy template/type through its registered factory, including
   locale, notifiable context, recipient authority, provider send time, and
   payload-version validation.
3. Quiesce legacy tracked sends and the legacy scheduled command. Drain active
   workers and reconcile every processing/expired-lock row against provider
   acceptance before copying data.
4. Import tracking history and only reconciled scheduled rows. Never replay an
   accepted/sent row, and never run both legacy and package schedulers.
5. Mount the host-owned package webhook controller, preview remote webhook
   drift, then switch the provider endpoint without leaving two lifecycle
   writers active.
6. Run the strict doctor, package integration tests, webhook-management dry
   runs, an isolated scheduled-processing smoke test, and row-count/status
   reconciliation before enabling new delivery.
7. Keep the legacy tables read-only through the rollback window. A rollback
   must disable package delivery before re-enabling the legacy writer.

Replace a legacy host MailerSend adapter with the optional built-in
`Nvl\MailNotifications\Adapters\MailerSend\MailerSendAdapter`, or keep a custom
adapter only when it implements the same provider-neutral contracts. The
built-in adapter has no MailerSend SDK dependency, remains unregistered by
default, verifies the exact raw body, extracts provider message identity, and
normalizes v2 activity payloads. It now maps `soft_bounced` to retryable
`delayed`; only hard or generic bounces are terminal `bounced`.

Webhook HTTP endpoints must accept only `POST` JSON requests, preserve the
exact raw body, and return `2xx` for authenticated acknowledgements. Published
configuration now includes allowed JSON content types, bounded MailerSend event
timestamp age/future skew, and a separate unmatched-event policy. The default
retries recent lookup misses for five minutes to recover tracking-row races,
then acknowledges older events from untracked Mailables on the same provider
domain.

Do not port legacy recipient-based webhook correlation. The package resolves a
verified event only by `correlation_id`, or by the exact provider and provider
message identifier when correlation is absent. More than one candidate is
acknowledged as `ambiguous_event` without mutation or retry and emits
`WebhookEventAmbiguous`; normal package uniqueness makes this a broken-schema
or custom-lifecycle alert. Repair the identity source instead of choosing a row
by recipient, subject, category, or timestamp.

Remote MailerSend webhook management is a separate opt-in. Register
`MailerSendRemoteWebhookManager` under `extensions.webhook_managers`, enable its
management configuration, and provide the API token, domain ID, unique name,
deployed HTTPS callback URL, supported activity events, version 2, timeout, and
pagination bounds. Preview and apply explicitly:

```bash
php artisan nvl:mail-notifications:webhooks:sync --provider=mailersend --dry-run
php artisan nvl:mail-notifications:webhooks:sync --provider=mailersend
```

Use `--force` only after reviewing configuration drift. Removal targets only
the unique configured name unless `--all` is explicitly supplied. The package
never calls the API at boot and never prints response bodies or signing
secrets. After creation, retrieve the generated per-webhook signing secret from
MailerSend, set `MAIL_NOTIFICATIONS_MAILERSEND_SIGNING_SECRET`, reload cached
configuration, and run the strict doctor. The fixed `webhook.test` secret is
only for URL validation.

Build any legacy admin screen from the package's authorized, privacy-bounded
read Actions instead of retaining a second write path. After the import, run:

```bash
php artisan nvl:mail-notifications:doctor --strict --format=json
```

Do not enable tracked delivery until every doctor error is resolved. Keep the
configured storage connection and table names stable through deployment and
forward schema evolution.

If provider acceptance succeeds but its tracking update fails, the emitted
`MailTrackingFailed` includes the resolved `ProviderMessageId` when available.
Queue an idempotent repair of that existing `TrackingAttempt`; never resend the
Mailable to repair local state.
