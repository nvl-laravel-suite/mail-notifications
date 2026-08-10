# Changelog

All notable changes to `nvl/mail-notifications` are documented here.

## [Unreleased]

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
  PostgreSQL/MySQL-family databases, paired SQLite triggers, adversarial
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
- Added fail-closed database capability gates for MySQL 8.0.16+ and MariaDB
  10.3+ through Laravel's `mariadb` driver, including active session constraint
  enforcement, physical table prefixes, and PostgreSQL schema-qualified
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

## [1.0.0] - Unreleased

- Initial standalone package implementation.
