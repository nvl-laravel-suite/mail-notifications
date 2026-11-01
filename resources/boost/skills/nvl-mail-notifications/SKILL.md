---
name: nvl-mail-notifications
description: Use when integrating tracked Laravel mail, scheduled mail factories, verified provider webhooks, authorized delivery history, privacy retention, testing interception or mail model extensions with nvl/mail-notifications.
---

# NVL Mail Notifications

The package targets PHP 8.4 and Laravel 13 and exposes complete native services.
The host owns mailables, localized copy, Markdown/HTML themes, routes and response projections.
No presentation UI, Actions, controllers or HTTP endpoints are installed by the package.

## Portable references

- [Installation](references/installation.md): provider, publish tags, migration ownership and configuration sections.
- [Native API](references/api.md): every public contract, signature, value object and result shape.
- [Usage](references/usage.md): tracking/factory setup, native examples, safe reads, replay and maintenance.

Read usage for capability selection and the API before constructing provider/factory data.
Read installation for new hosts or schema/adoption changes.
All references remain local to the skill after Laravel Boost publication.

## Choose the capability

- `TrackableMessage` plus `TracksMailDelivery`: opt host Laravel mailables into tracking.
- `DatabaseTrackingLifecycle`: native delivery identities, transitions and event replay.
- `MailHistory`: authorized delivery detail, pages, notifiable history, statistics and suggestions.
- `ScheduledMailScheduler`: schedule, cancel, reschedule or replace pending messages.
- `ScheduledMailReader`: authorized bounded scheduled detail/pages/statistics.
- `ScheduledMailProcessor` / `ScheduledMailRecovery`: committed claim, transport, retry and stale-claim recovery.
- `WebhookProcessor`: verify a named provider request before normalization/persistence.
- `MailHistoryAnonymizer` / `MailRetentionPruner`: bounded privacy preview/apply operations.
- Provider/message-ID/notifiable/factory/webhook-manager registries: explicit host extensions.

## Preserve real scheduling types

`ScheduledMailScheduler::schedule` accepts `ScheduleMailData`, not an invented array replacement.
That domain value validates alias/version/attempt/timing semantics.
Use `ScheduledRecipients` and `Recipient` for normalized deduplicated TO/CC/BCC envelopes.
Register stable `ScheduledMessageFactory` aliases and explicitly supported payload versions.
Factories build mailables without choosing recipients; the native scheduler owns the envelope.
Unknown payload fields are the host factory's validated schema responsibility.

## Authority and privacy

Bind host history and scheduled-read policies; defaults deny.
Readers reload canonical Eloquent actors before host permission checks.
Other principal providers retain their freshness contract.
Registered notifiable identity and live native owner scope remain authoritative.
Never infer permission from null, current guard or historical/background identity.
Return bounded safe reader arrays, not payloads, full recipients, metadata, claim tokens or raw errors.

## Transactions and integrations

Scheduling mutations own actual source transactions and publish events after outer commit.
Processing/claim/transport must start outside an ambient ScheduledMailMessage source transaction.
Preserve fenced claims, stored delivery identity, retries and recovery.
Transport acceptance is not a distributed exactly-once guarantee.
Settings-backed profiles require the selected provider; ordinary configured profiles work without it.
Testing interception is independent of tracking and remains a global delivery safety control.
Keep sensitive-storage encryption, status replay and worker state restoration intact.

## Configured models and maintenance

Set `models.notification` and `models.scheduled_message` before boot to concrete native subclasses.
Retain UUIDs, casts, UTC timestamps, ownership, query/persistence hooks and native resource classification.
Both roots and fixed technical rows share package-configured table/connection storage.
Subclass-only storage splits reject; configure the whole package graph instead.
The usage reference includes exact command names, factory/schedule/read examples and webhook handling.
