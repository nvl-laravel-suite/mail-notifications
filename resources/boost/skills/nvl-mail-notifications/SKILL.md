---
name: nvl-mail-notifications
description: Use for native NVL mail tracking, history, scheduling, verified webhooks, privacy retention, and testing interception.
---

# NVL Mail Notifications

Use complete package services for domain operations. Host applications own mailables, localized copy, Markdown or HTML themes, controllers, routes, request validation, admin resources, and frontend types. The package does not publish a presentation UI, Actions, or HTTP endpoints.

## Native operations

- `MailHistory`: authorized `get`, `getByProviderMessage`, `paginate`, `forNotifiable`, `statistics`, `suggest`. Results are bounded safe arrays. Accept allowlisted filter arrays and an explicit `Authenticatable` actor.
- `ScheduledMailReader`: authorized `get`, `paginate`, `statistics` with safe arrays. Never expose payloads, all recipient lists, claim tokens, metadata, or last errors in host responses.
- `ScheduledMailScheduler`: `schedule(ScheduleMailData)`, `cancel`, `reschedule`; `ScheduledMailProcessor::process` and `ScheduledMailRecovery` retain claim, delivery, and retry logic.
- `WebhookProcessor::process`: receive a named provider and a `WebhookRequest` assembled by the host. Signature verification must happen before normalization or persisted effects.
- `MailHistoryAnonymizer` and `MailRetentionPruner`: bounded history privacy operations. `MailAdoption` handles explicitly configured legacy storage migration.

Bind host read authorization for history and schedules. Resolve notifiables and owner scopes from canonical persisted identity. Use native ownership context and durable producer envelopes in workers. Never infer authorization from a null actor, current guard, or background identity. Provider adapters and factory implementations are host extensions.

Settings is optional; configured delivery profiles work without it. Explicitly requested Settings-backed profiles require its loaded provider. Testing interception is a global delivery safety control and must be checked independently from tracking. Keep encrypted storage, status replay rules, event dispatch after commit, and worker state restoration intact.

Run affected Pest tests, package formatting, and owning analysis after changes. Preserve PostgreSQL claim/failure race tests and committed-delivery tests for integrated environments.
