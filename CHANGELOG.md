# Changelog


All notable changes to `nvl/mail-notifications` are documented here.

## [Unreleased]

### Fixed

- Mail Notifications Doctor accepts exact Laravel migration history on the default database when Mail Notifications stores its tables on a separate connection.

## [7.0.4] - 2026-11-13

### Fixed

- Route webhook delivery state updates, testing interception, and Doctor migration checks through configured connections with PostgreSQL-safe savepoints and wrapped domain search predicates.

<!-- NVL GENERATED CHANGES START -->
### Changed

- harden package runtime safety, PostgreSQL portability and test performance
<!-- NVL GENERATED CHANGES END -->

## [7.0.3] - 2026-11-11

### Fixed

- A provider rate-limit cooldown no longer breaks sending on the Redis cache store, which returns the stored timestamp as a numeric string; a cooldown that cannot be stored is reported instead of leaving the attempt `sending`.
- A stored provider event of an unlisted type without `message_id` is ignored instead of throwing, an unreadable receipt is closed as `failed`, and one receipt that cannot be applied no longer blocks webhook reconciliation for the receipts behind it; it is reported, retried on the unmatched-receipt schedule and then closed as `failed`.
- Client callbacks that cannot be queued on a durable queue connection are reported instead of being dropped silently; callback recovery fails once with the cause, and the doctor adds a `managed.client_webhook_queue` warning.
- `ProviderWebhookAdapter::normalize()` rejects authenticated payloads that fail the provider schema with `ManagedProviderWebhookRejected` (`invalid_payload`) as documented, instead of letting Laravel's `ValidationException` escape.
- The doctor adds a `retention.coverage` advisory naming the non-terminal notification statuses (by default `pending`, `accepted` and `delayed`) that neither retention nor anonymization reaches. What is pruned or anonymized is unchanged.
- `failed_at` on a tracked notification is set only for `bounced`, `rejected` and `failed`; `complained` and `unsubscribed` no longer record a failure time.
- A tracked Mailable sent through a scheduling delivery profile, or handed to a mailer instance other than its own, records the mailer that actually sends it and resolves its provider and tracking exclusions from that mailer.
- The previous `Managed` class names are aliased on first use instead of loading about 117 package classes in every PHP process; `instanceof`, type hints, `new` and serialized payloads keep working.
- Removed settings that nothing enforced: `managed.authentication.requests_per_minute`, and the `rate_limit_per_minute` and `authentication_mode` attributes of `ClientMailSettings` (the columns stay, unused). `ClientAuthenticationMode` is deprecated.
- Concurrent `process-scheduled` workers on PostgreSQL claim the next free due messages with `SKIP LOCKED` instead of queueing on the same row.
- One malformed element no longer rejects a verified SendGrid webhook batch: valid events are accepted and the malformed count is reported. A batch that fails verification or has no valid event is still rejected.
- An exception thrown while a host factory builds a scheduled Mailable is retried within `max_attempts` like a transport failure instead of failing the message at once. Invalid stored payloads, recipients and unknown factories still fail terminally.
- A tracked notification with several recipients keeps the most severe status any provider event reported, whichever order events arrive in; an older event no longer loses to a newer, less severe one, and a bounce is recorded after a delivery.
- Receipt reconciliation no longer queries the `received` state, which nothing writes.
- `delivery:reconcile-webhooks --provider-account` and `delivery:integrations:sync` refuse a value that is not a UUID instead of failing in the database, and the restore quarantine names the canonical `NVL_MAIL_NOTIFICATIONS_DELIVERY_ENABLED` switch.

<!-- NVL GENERATED CHANGES START -->
### Changed

- correct scheduling, provider sync, webhooks and settings
<!-- NVL GENERATED CHANGES END -->

## [7.0.2] - 2026-11-09

### Changed

- Refresh the inherited Ownership deletion API reference for ordinary models and native pivots.

<!-- NVL GENERATED CHANGES START -->
### Changed

- refresh inherited ownership deletion contracts
<!-- NVL GENERATED CHANGES END -->

## [7.0.1] - 2026-11-05

<!-- NVL GENERATED CHANGES START -->
### Changed

- apply verified delivery release fixes
<!-- NVL GENERATED CHANGES END -->

## [7.0.0] - 2026-11-03

- Adopt the reviewed NVL 7 family for PHP 8.4+ and Laravel 13, with compatible required and optional NVL peers. Review the package upgrade guide before deployment.

### Changed

- Ship a prepared-delivery-first agent skill with portable canonical guides, corrected alpha rollback semantics and tested localized private-attachment recipes.
- Keep prepared delivery, ordinary Laravel transport tracking and the older Mailable scheduler explicitly independent; document the minimal delivery-only host profile.
- Honor package/capability disable controls in both native acceptance and queued provider submission without discarding accepted intents or uncertain-claim evidence.
- Reuse live host/package testing-setting precedence for prepared messages so package-only installations cannot bypass configured recipient interception.
- Update schema diagnostics for the single initial alpha schema; retain existing APIs, tables, message identities and frozen content.
- Align NVL peers and consumer contracts on the coordinated 7.x line. Review [UPGRADING.md](UPGRADING.md) for adoption and application-owned integration requirements.

## [6.0.1] - 2026-10-21

### Changed

- Use the shared Ownership resource backfill while preserving retained delivery history, inherited ownership and package verification.
- Require optional Ownership >=6.0.1 when installed so new shared backfill callers cannot resolve an older runtime.

## [6.0.0] - 2026-10-20

### Release fixes

- Include the existing native ownership default in published common configuration.

### Changed

- Capture native owner context for scheduled delivery, tracking and worker execution; preserve producer identity and scoped host configuration.
- Align required/development NVL peers on `^6.0`, optional peer conflicts on major 6, and `dev-main` on `6.x-dev`. Review [UPGRADING.md](UPGRADING.md) before rollout.

## [5.0.1] - 2026-10-08

### Changed

- Correct published-family verification and adoption guidance for local Dagger CI; runtime contracts are unchanged.
- Clarify that published creator migrations are immutable and retained installations need reviewed forward changes.


## [5.0.0] - 2026-10-08

### Changed

- The scheduler exposes exactly its four native methods through ScheduledMailSchedulerContract and retains its singleton identity. Existing tracking/sensitive-storage extension defaults now retain host bindings. Document contract substitution and truthful host fixtures in Testing your app.
- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Adopt lockstep major 5 with required and development NVL peer floors of `^5.0`.
- Scope webhook processing to each lifecycle and make global Markdown/component adoption explicit.
- Render package mail through its own presentation namespace while preserving host mail configuration.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [2.2.1] - 2026-09-26

### Documentation

- Clarify public support, contribution, and private security reporting paths.

## [2.2.0] - 2026-09-25

### Changed

- Prepare `nvl/mail-notifications` for independent Composer and Git publication; require `nvl/core` for shared Support and Data services.

## [2.0.1] - 2026-09-22

### Added

- Added persisted tenant envelopes for scheduled mail, tenant-scoped tracking
  and retention, stored-identity webhook restoration, fresh factory resolution,
  approved Settings-selected delivery profiles, adoption, and diagnostics.

### Fixed

- Enforce scheduled recipients after all Mailable build and envelope callbacks,
  while preserving host mailer selection, global recipient interception, and
  tracking of the final delivery envelope.

## [2.0.0] - 2026-08-29

### Changed

- Established authorized administrative read Actions and `TrackingLifecycle`
  as the 2.0 consumer boundary; direct consumer delivery-model queries now fail
  Suite audit.

## [1.0.7] - 2026-08-22

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.

## [1.0.6] - 2026-08-13

### Added

- Added authorized, safe-projection delivery lookups for an exact registered
  notifiable identity and exact registered provider message identity.
- Added separately authorized scheduled-mail list, detail, and statistics
  Actions with bounded filters and primary-recipient-only safe projections.

### Changed

- Extended Doctor visibility across both fail-closed administrative read
  authorizers, required host scheduler entries, and the enabled scheduler's
  minimum registered factory requirement.

## [1.0.5] - 2026-08-12

### Changed

- Documented and verified host-owned scheduling, shared scheduler locks, and
  the canonical package configuration namespace for webhook and MailerSend
  readiness.

## [1.0.2] - 2026-08-12

- Added a bounded, manifest-driven legacy adoption command with separate
  dry-run staging/import phases, explicit scheduled factory mappings, safe
  metadata/event conversion, identity reconciliation, host foreign-key repair,
  and opt-in source removal.
- Added fail-closed authorized list, show, statistics, and suggestion Actions
  with bounded filters and privacy-safe delivery-history value objects.
- Registered public backed enums with NVL Data when the suite TypeScript source
  registry is available, including `MailDeliveryStatus`.
- Registered migration publication through Laravel's timestamp-aware API and
  made Doctor warn when automatic vendor loading overlaps a published host copy.
- Added a provider-neutral tracking lifecycle and package-owned schema.
- Added configurable tokenized Laravel Markdown HTML/text components, optional
  automatic loading, and conventional host view publishing.
- Added environment-aware testing interception for every Laravel mailer that
  defers to the host's existing `mail.testing` configuration.
- Hardened runtime feature switches to require actual booleans instead of
  accepting unsafe PHP truthy-value coercion.
- Added a separately bounded MailerSend API connection timeout alongside the
  total request timeout.
- Added explicit Mailable opt-in, per-instance opt-out, global disablement, and
  configurable mailer exclusions.
- Made fluent notifiable and metadata overrides one-delivery state that survives
  queue serialization and is cleared after successful or failed send attempts.
- Added idempotent queued-Mailable terminal failure reconciliation with a
  non-sensitive queue reference, deterministic concurrent fallback fencing,
  preserved/redacted one-delivery context, nullable manual-failure handling,
  and an explicit helper for host-defined failure hooks.
- Added standard Symfony message identifier correlation for SMTP and other
  Laravel transports without provider SDK dependencies.
- Added the resolved provider identity to post-acceptance
  `MailTrackingFailed` events so hosts can repair tracking idempotently without
  resending an already accepted message.
- Added configuration-first provider adapters, standalone resolvers,
  notifiable aliases/providers, lifecycle and redactor replacement, bounded
  webhook processing, and public container tags for advanced integrations.
- Added durable provider-event idempotency, monotonic transitions, privacy-safe
  metadata redaction, after-commit events, and readiness diagnostics.
- Bound observational event delivery to the exact configured storage
  transaction, preventing unrelated host connections from delaying committed
  package events and suppressing events on storage rollback.
- Added explicit, bounded database retention with dry-run previews, UTC
  lifecycle cutoffs, status allowlists, transactional provider-event cleanup,
  separately opt-in terminal scheduled-message pruning, and doctor validation.
- Added a separately opt-in, bounded, dry-runnable history-anonymization stage
  with per-data-set limits, durable idempotency markers, scalar/content
  clearing, terminal scheduled-message protection, and retained correlation
  identities for webhook and queue safety.
- Added optional versioned sensitive-array storage through a configurable
  transformer contract, including a Laravel encrypter implementation,
  plaintext legacy reads, current/previous-key rotation, strict unreadable
  history failures, and doctor round-trip validation.
- Added a JSON-safe version 2 sensitive-storage envelope for arbitrary binary
  transformer output while retaining version 1 reads, and prevented late
  acceptance, failure, or provider-event reconciliation from rehydrating
  anonymized metadata.
- Added driver-native database status invariants for notifications, provider
  events, and scheduled messages, with exact named `CHECK` constraints on
  PostgreSQL, paired SQLite triggers, adversarial
  definition checks, and readiness diagnostics.
- Added retention-aligned notification and scheduled-message indexes, including
  legacy timestamp fallbacks, with migration and doctor compatibility checks.
- Hardened preview and post-send Mailable serialization, conflicting webhook
  event identities, delayed/engagement race handling, bounded cyclic metadata,
  safe observational event listeners, and schema constraint diagnostics.
- Hardened the migration compatibility preflight to validate full column,
  privacy-marker, status-invariant, foreign-key, and exact named-index contracts
  and reject ambiguous ownership when the package creator is absent from
  migration history.
- Added readiness checks for physical table prefixes and PostgreSQL schema-qualified
  ownership.
- Consolidated queue-reference identity, status invariants, privacy markers,
  and their indexes into the unpublished first-release creator migrations,
  removed the corrective migration chain, and kept all package rollbacks as
  forward-only no-ops that retain mail history.
- Aligned final-recipient capture with the transport boundary, preserved
  internationalized recipient identities, made provider acceptance immutable,
  added metadata breadth/byte budgets, and expanded production-readiness
  diagnostics.
- Canonicalized sensitive metadata keys across snake, kebab, camel, and spaced
  styles and expanded defaults for verification codes, magic links, and OTPs.
- Made custom mailer-contract behavior deterministic: concrete Laravel mailers
  are tracked at their Symfony transport, while hidden transports honor the
  configured fail-open or fail-closed policy.
- Kept published mail-view overrides, content, recipient policy, transport
  configuration, and application delivery surfaces host-owned.
- Added explicit UTC provider-submission availability for scheduled mail,
  including initial timing-order validation, atomic reschedule/replace semantics,
  factory data and mutation events, while leaving actual provider `sendAt`
  configuration host-owned.
- Preserved UTC microsecond timestamps at model, raw-write, scheduler,
  retention, and anonymization boundaries independently from the host
  application timezone.
- Rejected list-shaped top-level scheduled payloads and metadata before create
  or replacement persistence.
- Added package-owned, host-neutral Eloquent factories for tracking records,
  provider events, and scheduled messages with coherent lifecycle, claim,
  retry, and terminal states.
- Added a typed privacy-safe webhook ambiguity surface that acknowledges broken
  multi-candidate correlation without mutation or retry, emits an after-commit
  event, and defensively enforces strict non-recipient identity lookup.
- Added a PostgreSQL-only two-worker claim race that proves due rows receive one
  attempt and one unique fence, then exercises recovery, reclaiming, and stale
  token rejection without adding a runtime process-control dependency.
- Added a PostgreSQL-only queued-failure insert race that proves deterministic
  fallback uniqueness and exactly-once lifecycle events, with per-test
  `migrate:fresh` restricted to explicitly named disposable databases.

## [1.0.0] - 2026-08-08

- Initial standalone package implementation.
