---
name: nvl-mail-notifications
description: Use when embedding nvl/mail-notifications in Laravel for prepared HTML/CSS, Mailables, client-scoped delivery, attachments, providers, event tracking and callbacks, or selecting its separate remote consumer bridge.
---

# NVL Mail Notifications

PHP 8.4+, Laravel 13. This Composer package embeds the delivery engine. Hosts own HTTP authentication, routes, admin UI, customer permissions and content rendering. A remote consumer uses `nvl/mail-notification-bridge` with a service-issued client ID/API token and service URL; it does not install this engine or receive provider credentials.

## Read by task

- [Start and choose a capability](references/usage.md): engine versus bridge, minimal setup and operator prerequisites.
- [Native delivery and Mailable recipes](references/delivery.md): accepted fields, live authorization, localization, attachments and prepared-payload reuse.
- [Architecture](references/architecture.md): boundaries, optional capabilities and standard namespaces.
- [Database](references/database.md): single initial alpha migration, destructive rollback, connection/table ownership and Media links.
- [Operations](references/operations.md): workers, provider inventory sync, verified inbound webhooks, signed outbound callbacks, pause/recovery and retention.
- [Optional Laravel tracking/scheduling](references/laravel-tracking.md): use only when explicitly requested; real scheduler values and protected read policies.
- [Installation/publication](references/installation.md) and [public API lookup](references/api.md): exact provider, tags, command names, signatures and shapes. Search the relevant symbols instead of reading the entire catalogue.

All references travel with the published skill. They require no monorepo or application fixtures.

## Prepared-delivery invariants

Use `Services\ManagedDelivery::send` or `Laravel\Concerns\SubmitsMailDelivery` with an existing `Models\Client`. For delivery-only hosts enable `managed.enabled` and disable ordinary `tracking.enabled` and older `scheduling.enabled`; prepared `send_at` still works. Do not add templates, campaign orchestration, broker identity management or package HTTP routes.

Authorize the current client through an explicit host check that throws or returns false, or the documented authenticated Gate. Null/background execution never grants access. Caller input cannot choose provider credentials or override client/owner authority. Owner defaults and client settings select an eligible verified sender.

Prepare once when previewing and sending the same input; acceptance applies CSS and sender resolution. Reuse that payload and a stable client-scoped idempotency key for retries. Sender/body/attachment snapshots survive later host edits. Acceptance and SMTP/provider submission do not prove inbox delivery.

Use native Media storage and an explicit host authorization/scanner policy. Keep private bytes private; retained attachment evidence survives byte expiry. Respect actual source transactions and outer-commit dispatch. An interrupted submission can be `unknown`: reconcile evidence before any resend. Preserve callback event IDs, exact signed body bytes, receiver deduplication and durable retry/recovery.

Canonical implementations use normal `src/Models/`, `src/Services/`, `src/Jobs/`, `src/Enums/` and `src/Contracts/` directories. Previous names and persisted morph identities are compatibility data, not an alternative implementation tree. Do not rewrite IDs, queued payloads or applied migration history during upgrades.
