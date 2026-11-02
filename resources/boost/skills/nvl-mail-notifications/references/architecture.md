# Delivery package architecture

`nvl/mail-notifications` is an embedded Laravel delivery engine. `nvl/mail-notification-bridge` is the separate HTTP consumer SDK. Install the engine in an app that owns sending, providers, storage and workers; install the bridge in an app that calls that service. Neither requires Template Maker. HTML, CSS and plain text are prepared in the consuming application.

## Directory and capability ownership

```text
src/
  Models/                     Owner/client settings, messages, attempts, events and tracking records
  Enums/                      Persisted classification and lifecycle vocabulary
  Contracts/                  Provider and host-authorization boundaries
  Services/
    ManagedDelivery.php       Public authorized prepared-content acceptance boundary
    MessageInputValidator.php Shared native/HTTP payload validation
    MailablePreparation.php   Host Mailable to prepared content, without submission
    Delivery/                 Internal dispatch, submission, bytes, callbacks and reconciliation
    Integrations/             Paginated provider administration and synchronization
    Flags/                    Client-scoped rule matching and saved evidence
    MailHistory.php           Existing protected Laravel-tracking history reader
  Jobs/                       Identifier-only background work and checkpoint recovery
  Console/Commands/           Bounded operational commands
  Laravel/                    Mailable traits, listeners and transport integration
  Providers/                  Container registration and opt-in lifecycle hooks
  Support/                    Canonical storage/configuration and previous-name aliases

database/
  factories/                  Typed isolated fixtures
  seeders/                    Integration catalogue metadata
  migrations/                 One initial alpha create/drop schema
```

There is no `src/Managed` implementation tree. That folder was an extraction boundary separating the newly imported delivery functionality from the package's existing tracking API; it was not a tenant, permission or deployment boundary. The implementations now use the ordinary package namespaces. Existing `Managed` class identities are Composer bootstrap aliases to those implementations, not duplicate proxy classes. Container aliases preserve the same configured singleton for old and new service names. Configuration keys and command names remain compatible.

## Two supported uses

**Prepared-content delivery** uses `Services\ManagedDelivery`. It accepts an explicit `Models\Client`, validates content, rechecks live host authorization, enforces client/owner/account eligibility, preserves idempotency and enters the persisted delivery workflow. Enable `managed.enabled`. HTTP controllers, client-key authentication, operator UI and business authorization remain host-owned.

**Laravel transport tracking and scheduling** observes mail sent by Laravel and retains the existing `Models\MailNotification`, `Models\MailNotificationEvent` and `Models\ScheduledMailMessage` contracts. It uses the host's Laravel mailer and configured tracking policy. It is independently controlled by `tracking` and `scheduling` configuration. These APIs remain supported; they are not a second copy of prepared-message acceptance.

An application can enable either capability. The standalone mail service enables prepared delivery and disables ordinary tracking to avoid describing its own provider submission twice. `Services\WebhookProcessor` belongs to the established tracking API; `Services\Delivery\WebhookProcessor` reconciles provider activity for persisted delivery intents. Choose the public capability boundary, not an internal helper with a similar name.

## Recommended focused delivery profile

A host using prepared HTML/CSS and the Mailable submission trait needs only this capability selection:

```php
return [
    'managed' => ['enabled' => true],
    'tracking' => ['enabled' => false],
    'scheduling' => ['enabled' => false],
];
```

The standalone service uses this profile. Package defaults retain ordinary Laravel tracking compatibility for existing hosts; enabling prepared delivery does not silently change their independently chosen tracking behavior. The public entry points are `ManagedDelivery::send`, `SubmitsMailDelivery::toMailDelivery` and `SubmitsMailDelivery::submitMailDelivery`. Choose one submission path for a given email; calling both Laravel's normal `Mail::send` and native submission would create two independent deliveries.

| Capability | Role in the focused service |
| --- | --- |
| Prepared content, sender eligibility, recipients, attempts and provider events | Core delivery and traceability |
| Idempotency, submission leases, uncertain-state reconciliation and file staging | Core recovery guarantees; retain these even for a small installation |
| Business references, language, classification and bounded metadata | Correlation fields, not application authorization or template rendering |
| Client callbacks | No outbound callback until the client explicitly registers an active subscribed endpoint |
| Provider domain/sender sync | Explicit queued operator work; no automatic polling of every account on application boot |
| Flag rules and reusable assets | Used only when configured; ordinary HTML plus per-message attachments does not require a reusable catalogue |
| Ordinary Laravel transport tracking and factory-based Mailable scheduling | Supported separately; off in the standalone delivery service |
| Templates, campaign builders, audience/contact management and customer business permissions | Outside this package's responsibility |

`send_at` remains available for prepared-message dispatch when the older Mailable scheduler is disabled. Marketing classification, opt-outs and provider suppressions remain delivery policy; they do not introduce campaign orchestration. Do not merge messages, attempts, receipts, staging or callback records solely to lower the table count: they carry different retry, ownership and retention lifetimes.

## Isolation, transactions and effects

Owner records group client identities and provider accounts. Client identities survive credential rotation; settings and hashed API keys are separate records. Provider credentials never belong in a message payload. The service checks that the effective account/sender belongs to the current owner. Client marketing opt-outs are distinct from provider-level suppressions.

`ManagedDelivery::send` is the public native authorization boundary. Without an explicit authorizer it requires an authenticated principal allowed by the host Gate. Internal lifecycle and maintenance services assume a previously authorized intent or a trusted bounded operational caller. A raw model query is not an authorization decision: hosts must retain client/owner predicates for administrative reads and mutations.

Canonical `connection` and `tables` configuration applies to the graph. Transaction and after-commit work uses the actual package connection. Acceptance journals staged bytes and keeps idempotency evidence; dispatch follows commit. A sending lease that expires can mean the provider accepted the mail, so recovery marks uncertainty and reconciles rather than blindly resending.

Native Media owns original-byte operations. Mail retains attachment names, sizes, checksums and expiry/deletion evidence independently of the file lifetime. Stable media owner aliases `mail.assets` and `mail.message-assets` do not change with PHP namespace cleanup. See [delivery](delivery.md), [database](database.md) and [operations](operations.md).

The default `Client::getMorphClass()` retains its previous persisted uploader identity so existing native Media rows keep the same owner and authorization checks. Explicit host morph aliases remain authoritative. Namespace cleanup requires no uploader data rewrite.
