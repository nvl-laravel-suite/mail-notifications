---
name: nvl-mail-notifications
description: Use for native NVL mail tracking, history, scheduling, verified webhooks, privacy retention, and testing interception.
---

# NVL Mail Notifications

Use complete package services for domain operations. Host applications own mailables, localized copy, Markdown or HTML themes, controllers, routes, request validation, admin resources, and frontend types. The package does not publish a presentation UI, Actions, or HTTP endpoints.

## Configured models

Configure `nvl-mail-notifications.models.notification` and `models.scheduled_message` before providers boot with concrete subclasses of `MailNotification` and `ScheduledMailMessage`. `MailModelRegistry` freezes both classes while preserving native morph identities, UUID keys, required casts, ownership scope, persistence hooks and UTC timestamps. Native tracking, scheduling, reads, maintenance, factories, relations and queued restoration use the configured roots. `MailNotificationEvent` and technical receipt, delivery identity and lock rows remain fixed package records; the event's `mailNotification` relation targets the configured notification.

Both roots must retain the package-configured table and actual connection so each operation and its technical records share one transaction source. Configure `connection` and `tables` for the complete package graph; an individual subclass that selects divergent storage is rejected before persistence. Compatible extra columns and host presentation methods are supported, while native mutation payloads retain their existing allowlists. Resource classifications remain package controlled (`mail.notifications`, `mail.scheduled`, inherited `mail.events`); background identity alone still grants no host authorization.

## Native operations

- `MailHistory`: authorized `get`, `getByProviderMessage`, `paginate`, `forNotifiable`, `statistics`, `suggest`. Results are bounded safe arrays. Accept allowlisted filter arrays and an explicit `Authenticatable` actor.
- `ScheduledMailReader`: authorized `get`, `paginate`, `statistics` with safe arrays. Never expose payloads, all recipient lists, claim tokens, metadata, or last errors in host responses.
- `ScheduledMailScheduler`: `schedule(ScheduleMailData)`, `cancel`, `reschedule`; `ScheduledMailProcessor::process` and `ScheduledMailRecovery` retain claim, delivery, and retry logic.
- `WebhookProcessor::process`: receive a named provider and a `WebhookRequest` assembled by the host. Signature verification must happen before normalization or persisted effects.
- `MailHistoryAnonymizer` and `MailRetentionPruner`: bounded history privacy operations. `MailAdoption` handles explicitly configured legacy storage migration.

Bind host read authorization for history and schedules. Resolve notifiables and owner scopes from canonical persisted identity. Use native ownership context and durable producer envelopes in workers. Never infer authorization from a null actor, current guard, or background identity. Provider adapters and factory implementations are host extensions.

Settings is optional; configured delivery profiles work without it. Explicitly requested Settings-backed profiles require its loaded provider. Testing interception is a global delivery safety control and must be checked independently from tracking. Keep encrypted storage, status replay rules, event dispatch after commit, and worker state restoration intact.

Run affected Pest tests, package formatting, and owning analysis after changes. Preserve PostgreSQL claim/failure race tests and committed-delivery tests for integrated environments.
