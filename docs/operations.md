# Delivery operations and failure semantics

Keep provider credentials, SMTP settings, client API keys and callback signing secrets in trusted host configuration or encrypted account storage. Configure the package connection/table graph and native Media disks before enabling delivery. Use a durable Laravel queue and a worker compatible with the installed PHP/package version.

## Dispatch, submission and recovery

Acceptance persists an intent and idempotency identity before queue dispatch. The sender claims eligible work under a lease, rechecks current account/owner/client policy and records an individual attempt. Provider acceptance means `submitted`, not inbox delivery. Provider webhooks add recipient activity and projected outcomes later. A crash after provider acceptance can leave an `unknown` outcome: reconcile it before deciding whether another real submission is safe.

Run the normal Laravel queue worker for your configured connection/queue. Configure its timeout and backend retry interval consistently with job timeout and lease behavior. Review these bounded commands in `artisan list` / `--help` before scheduling:

| Command | Purpose |
| --- | --- |
| `nvl:mail-notifications:delivery:dispatch-pending --limit=100` | Dispatch due intents and recover abandoned submission claims |
| `nvl:mail-notifications:delivery:reconcile-webhooks --limit=100` | Match retained provider evidence to attempts |
| `nvl:mail-notifications:delivery:recover-provider-syncs --limit=100` | Recover due/abandoned provider page checkpoints |
| `nvl:mail-notifications:delivery:recover-client-webhooks --limit=100` | Recover persisted callback work |
| `nvl:mail-notifications:delivery:prune-attachments --dry-run` | Preview private-byte expiry work |
| `nvl:mail-notifications:delivery:prune-orphan-files --dry-run` | Preview abandoned staging/orphan cleanup |
| `nvl:mail-notifications:delivery:prune --days=365 --dry-run` | Preview eligible terminal-history retention |
| `nvl:mail-notifications:delivery:quarantine-restored --before=... --dry-run` | Preview restore quarantine before dispatch resumes |

The native queued jobs use one queue attempt and own their domain retries/recovery: `SendEmailMessage` and `ProcessProviderSyncPage` have 60-second timeouts; `DeliverClientWebhook` has a 20-second timeout. Configure the backend reservation/visibility timeout strictly above the longest effective job timeout with a safety margin. Submission leases are two minutes and callback leases one minute; do not lengthen worker/provider work beyond its claim without revisiting the recovery protocol. Prepared sends and provider-sync pages use the host default queue/connection, while client callbacks can use the operational `webhooks.connection` and `webhooks.queue` settings. A synchronous driver can execute work in the accepting process and is not a durable production queue.

The host owns Laravel scheduling and worker supervision. Retention uses bounded batches/time budgets; inspect failures rather than assuming one invocation drains an arbitrarily large account. Retain uncertain attempts and staged-file evidence until reconciled.

## Provider synchronization and webhooks

Run `nvl:mail-notifications:delivery:integrations:sync <account-uuid>` only after authorizing that provider account in host code or trusted operations. It starts the paginated domain/sender run; it does not seed or replace credentials. The integration catalogue is seeded separately through `Database\Seeders\IntegrationSeeder`.

Synchronization persists page cursors, phases, counts, retries and terminal status. It continues beyond the provider's first page, reuses durable checkpoints and fences stale workers. Expose those run records in the host dashboard instead of treating enqueue success as completion.

The host owns provider webhook routes and provider-specific signature verification. Pass the original raw request bytes to the pure signature-verifying normalizer first; persist only its verified observations, then enqueue receipt processing after commit. Outbound client callbacks are separate: immutable versioned bodies are signed, dispatched after the source commit and retried from persisted delivery state. Never replace either with an untracked in-memory HTTP callback. The remote consumer bridge supplies an optional durable signed receiver for consuming Laravel apps.

## Namespace upgrades and restore rehearsal

`Managed` implementation namespaces have moved to the ordinary `Models`, `Services`, `Jobs`, `Enums`, `Contracts` and `Database` namespaces. Previous names remain Composer-bootstrap class aliases and container aliases; already serialized jobs remain restorable. Keep the Composer-provided autoload file in your deployment and run normal package discovery. Update host imports deliberately, rebuild cached configuration as needed, and restart long-lived workers after deploying changed code. Do not rewrite queued serialized bytes or applied migration history.

A restored queue/database may contain mail already accepted by a provider. Pause sending (set `nvl-mail-notifications.managed.delivery.enabled=false` in the default configuration namespace, or `mail-service.delivery.enabled=false` when the host selects `managed.configuration_namespace=mail-service`), restore the database and private storage together, quarantine restored intents with an explicit cutoff, and reconcile provider evidence before resuming. The quarantine command requires delivery to be paused. A backup restore is not authorization to resend historical mail.

Use the package doctor plus your host's queue, storage and provider health checks. Readiness checks do not establish external inbox delivery or live webhook reachability. Local SMTP is an acceptance rehearsal; public production provider/webhook checks remain separate.

## Disable, pause and testing controls

The operational `delivery.enabled` value defaults to true. `managed.configuration_namespace` defaults to `nvl-mail-notifications.managed`; that namespace choice does not itself enable the managed capability. Configure booleans as actual booleans and rebuild cached configuration/restart workers after deployment changes.

`nvl-mail-notifications.enabled=false` or `managed.enabled=false` rejects new prepared submissions and prevents queued messages from reaching providers. Existing queued intents and pending attempts are preserved; re-enabling allows normal dispatch recovery. These switches cannot retract a submission already handed to a provider. When only the operational `delivery.enabled` setting is false, validated acceptance can continue while dispatch and submission wait.

Lease recovery, inbound event reconciliation and stored callback evidence remain useful while new sends are paused. An expired in-flight attempt still becomes `unknown`; it must never become an automatic resend simply because a switch changed. Pause controls govern new email submission, not deletion of historical evidence or cancellation of already published callback intents.

Recipient interception uses the same configuration resolution as ordinary Laravel-mail testing: an explicitly populated host `mail.testing` array takes precedence, otherwise `nvl-mail-notifications.testing` applies. No host mail-config extension is required for an embedded package install. Original To/Cc/Bcc values remain in the restricted testing snapshot; the effective envelope contains only the configured test inbox. Enabling interception after an un-intercepted message was accepted blocks that message before provider contact. Prepared delivery fails closed when enabled interception has an invalid inbox or disallowed environment.

## Provider receipt and client callback contracts

Inbound provider hooks and outbound consumer callbacks are different protocols:

| Direction | Verification and persistence |
| --- | --- |
| Provider to host | Resolve the configured account and call `Services\Delivery\ProviderWebhookAdapter::normalize($account, $rawBody, $contentType, $headers)` before any receipt effect |
| Host to consuming app | Subscribe an enabled `ClientWebhookEndpoint`; the package stores an immutable callback intent and sends signed HTTP with durable retry/recovery |
| Optional Laravel tracking PHP events | See the separate PHP event catalogue; these local after-commit notifications are not the prepared-delivery callback outbox |

The native provider adapter supports MailerSend, SendGrid and Mailgun JSON webhooks, with a 256 KiB raw-body limit. Pass original request bytes and the actual provider signature headers; do not re-encode JSON before verification. MailerSend uses the account's `webhook_secret`, SendGrid its `webhook_public_key`, and Mailgun its webhook signing secret. Provider API tokens are not interchangeable with webhook signing credentials. SMTP provides submission acceptance but has no domain/sender sync API or built-in downstream delivery/open/click webhook stream.

Normalization returns a list of `{payload, key}` observations, or rejects with `Exceptions\ManagedProviderWebhookRejected`; validation probes can return an empty list. It performs no database or transport effects. The host persists `Models\WebhookReceipt` deduplicated by `provider_account_id` and `payload_hash` (the returned key), with verified normalized payload and `verified_at`, then dispatches `Jobs\ProcessProviderWebhook` by receipt ID after the actual source transaction commits. The package's receipt reconciliation command recovers retained pending evidence if enqueueing fails. Map native rejection reasons to the host's existing HTTP contract. Never treat caller-supplied `verified_at` or a normalized payload submitted to an unverified route as proof.

Client callbacks use `webhook-id`, `webhook-timestamp` and `webhook-signature` headers. The signature is `v1,` followed by base64 of HMAC-SHA256 over `event_id + "." + timestamp + "." + exact_body`, using the binary key decoded from the `whsec_` signing secret. Event ID and body remain stable across retries; timestamps/signatures are refreshed. The JSON envelope has `version`, `id`, `type`, `occurred_at`, `client_id` and bounded `data` containing message status and business references. Legacy nullable template/composition correlation fields remain in version 1 for compatibility; they do not add template functionality.

Receivers verify signatures and timestamp tolerance before side effects, bind the event to the expected client/connection, deduplicate by stable event ID and body, persist before acknowledging, and process idempotently. Use `nvl/mail-notification-bridge` for the Laravel receiver; it supplies the signed middleware and durable inbox/recovery rather than requiring another custom verifier. A successful HTTP response is acknowledgement, not a guarantee that arbitrary listener side effects executed exactly once. Keep listener-level deduplication for external business mutations.

Production callback targets require allowed public HTTPS origins with DNS/address checks; redirects are not followed. Local HTTP exceptions require explicitly configured origins and an allowed local/testing environment. No client endpoint is registered automatically, and no callback is sent until one is enabled and subscribed. Current target/client policy is rechecked before delivery.

For a package-only development host, configure the testing section explicitly (in `config/nvl-mail-notifications.php`):

```php
return [
    'testing' => [
        'enabled' => true,
        'to_address' => 'qa@example.test',
        'to_name' => 'QA inbox',
        'respect_environment' => true,
        'environments' => ['local', 'testing'],
    ],
];
```

Use a real inbox you control when testing a real provider. This illustrates one section to merge into the host configuration, not a replacement for its chosen capability/storage configuration. If `mail.testing` is already a nonempty host array, update that authoritative section instead of expecting package defaults to override it.
