# Mail storage and schema ownership

The package has one alpha create/drop migration. It creates all 29 mail tables, including Media links and native ownership columns. Configure `connection`, `tables` and the migration owner before the first installation. Use either vendor-loaded migrations (`migrations.enabled=true`) or one published copy with the vendor loader disabled. Do not run both. `managed.enabled` changes behavior, not schema installation.

The names below are logical keys in `Definitions\Tables\MailNotificationsTables`; physical names are configurable. The standalone service maps these to its existing tables, and this namespace refactor changes no IDs, columns or table names.

| Tables | What they retain |
| --- | --- |
| `owners`, `clients` | Shared-owner and consuming-application identities |
| `owner_mail_settings`, `client_mail_settings`, `client_api_keys` | Owner defaults, client overrides, independently revocable hashed credentials |
| `integrations`, `provider_accounts`, `sending_domains`, `sender_identities` | Supported driver catalogue, encrypted owner account configuration, synchronized domains and senders |
| `email_messages`, `email_recipients`, `email_attempts`, `email_events`, `webhook_receipts` | Accepted content snapshots, To/Cc/Bcc state, individual submissions, normalized activity and inbound receipt evidence |
| `email_message_references` | Client-scoped user/order/other business identities for history lookup |
| `assets`, `message_assets`, `staged_mail_files` | Reusable client bytes, durable per-message attachment evidence, recoverable storage staging |
| `recipient_suppressions`, `marketing_opt_outs` | Provider-level delivery blocks and separate client marketing preferences |
| `email_flag_rules`, `email_message_flags` | Client match rules and saved message-tag evidence |
| `provider_sync_runs`, `provider_sync_pages` | Paginated synchronization progress and retryable page checkpoints |
| `client_webhook_endpoints`, `client_webhook_deliveries` | Client callback subscriptions and immutable delivery/retry intents |
| `notifications`, `events`, `scheduled_messages` | Existing Laravel transport-tracking and host-Mailable scheduling contracts, independent of prepared-content delivery |

The last three tables support established package APIs. They are not the service's prepared-message attempt/event tables. This cleanup retains those APIs rather than silently deleting a supported capability. The table count also includes explicit file/queue recovery evidence; collapsing those into live message fields would change their failure and retention guarantees.

## Relationships and ownership

Provider accounts, domains and senders belong to an owner. Each client has a stable owner relationship and independent settings/keys. Every business reference belongs to the same client as its message. Message recipients and attempts retain distinct outcomes; an aggregate message status is projected from their evidence.

Native Media owns stored files and its own tables. Mail owns the relationship to those original bytes and the attachment metadata. Expiring or deleting private attachment bytes does not erase the message's attachment name, MIME type, size, checksum or expiry/deletion timestamps. Stable Media owner aliases are `mail.assets` and `mail.message-assets`.

Raw Eloquent models are trusted internal records. Hosts must retain their own administrative authorization and client/owner predicates. The tracked/scheduled model roots can use the package's existing configured-model extension contract; that is not a promise that every delivery identity/model is replaceable through those two configuration entries.

The initial mail migration’s rollback drops the mail tables and data. That file does not remove Media-owned schema or physical files; a Laravel batch rollback can invoke other packages’ migrations too. Existing installations must not replay the initial migration or recreate data for a namespace change. See [operations](operations.md) for worker/restore handling and [architecture](architecture.md) for previous class identities.

The default `Client::getMorphClass()` retains its previous persisted uploader identity so existing native Media rows keep the same owner and authorization checks. Explicit host morph aliases remain authoritative. Namespace cleanup requires no uploader data rewrite.

The initial file is `2026_11_02_000000_nvl_mail_notifications_create_schema.php`. It requires fresh mail tables and rejects existing package tables before creating new ones. There is no separate managed or tenancy migration stream. A namespace update is not a fresh installation and does not justify replaying this file.
