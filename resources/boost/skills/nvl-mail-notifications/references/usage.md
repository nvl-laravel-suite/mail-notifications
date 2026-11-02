# Start with the right integration

## Embed sending or call an existing service

| Host responsibility | Package |
| --- | --- |
| Own provider credentials, mail records, private bytes, workers and sending | `nvl/mail-notifications` (this engine) |
| Call an existing Mail Notifications HTTP service from Laravel | `nvl/mail-notification-bridge` (remote SDK, Mailable/notification integration and optional callback receiver) |

The bridge uses the service base URL, a service-issued client API token, and the corresponding client ID. Its canonical settings are `nvl-mail-notification-bridge.base_url`, `token`, `client_id` or trusted named connections. It sends Bearer authentication; it never needs MailerSend/SendGrid/Mailgun credentials or access to this engine's database. Use the bridge's own bundled skill/API for HTTP queries, middleware and durable callback reception. Client identity selects isolation; the consuming application still authorizes which user/order/customer operation may send or inspect mail.

## Minimal embedded setup

1. Install `nvl/mail-notifications:^7.0`; Composer supplies its native Core/Media dependency closure. No Template Maker or frontend package is required. Follow [installation](installation.md) before migrating.
2. Use the [focused delivery profile](architecture.md#recommended-focused-delivery-profile). Configure the canonical package connection/table map and Media connection before boot/migration. Mail, Media and its owner-slot ledger must share the same actual Laravel connection.
3. Configure separate admitted private/public disks, safe delivery policies and nonempty Media cleanup roots. Bind the host's Media authorization and deliberate scanner policy; see [delivery prerequisites](delivery.md#storage-and-authority-prerequisites). A no-op scanner does not establish malware safety.
4. Seed the integration catalogue from the host seeder, provision an owner, client and encrypted provider account through the host's trusted administrative workflow, and run the [queued inventory sync](operations.md#provider-synchronization-and-webhooks) for API providers. Choose a verified sender and create client mail settings using owner defaults or explicit overrides. The package installs no owner/client/API-key provisioning endpoints.
5. Choose durable Laravel queue/cache infrastructure and run a worker. Schedule bounded pending-dispatch, receipt-reconciliation, sync/callback recovery and byte-retention commands from [operations](operations.md). No schedule or worker is installed automatically. Run the package doctor locally; provider delivery and public callbacks still require separate real integration checks.
6. Use [ManagedDelivery and the executable Mailable recipe](delivery.md). For normal embedded calls, no service-issued HTTP client API token is required: the host passes a persisted client and explicit current authorization. Use ordinary [Laravel tracking/factory scheduling](laravel-tracking.md) only when that separate capability is needed.

The initial alpha migration creates the complete mail graph once. Its rollback destroys mail tables and data; it is not an upgrade or a safe way to turn a capability off. Runtime disable/pause controls preserve existing message evidence. See [database](database.md) and [operations](operations.md#disable-pause-and-testing-controls).

## Keep transport separate from the native service

The host parses multipart POST uploads, verifies its API client credentials, resolves the current persisted client and maps exceptions to its HTTP contract. `MessageInputValidator` supports native calls and host FormRequest rules; the public `ManagedDelivery` boundary validates independently. Unknown/protected authority fields reject. `ManagedDeliveryConflict` is a native conflict; a host HTTP adapter may map it to 409. Validation/authorization failures use Laravel exceptions.

The host also owns provider webhook routes: verify original bytes with `Services\Delivery\ProviderWebhookAdapter`, persist deduplicated verified receipts, then queue processing after commit. Do not pass prepared-engine events to the optional tracker `Services\WebhookProcessor`. Outbound client callbacks are a third boundary with their own signing secret, stable event ID and receiver deduplication.

## Maintain docs and skills

The installed Composer package carries this complete skill tree. Publish only the skills tag from [installation](installation.md) when adopting it in a consumer; do not blanket-publish migrations during skill updates. `api.md` and `installation.md` are generated, and prepared-delivery guides are portable copies of canonical package docs. Keep host conventions separate from vendor guidance. Existing public contracts and stored identities remain compatible; use canonical imports in new code.
