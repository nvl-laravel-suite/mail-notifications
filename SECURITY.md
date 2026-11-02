# Security Policy

Report vulnerabilities through [the package’s private reporting form](https://github.com/nvl-laravel-suite/mail-notifications/security/advisories/new). Public reports must not contain credentials, callback secrets/signatures, private content, reset links, addresses or raw provider payloads.

## Supported source and deployment evidence

The current 7.x source targets PHP `^8.4` and Laravel `^13.0`, subject to the installed NVL dependency closure in Composer. It does not declare Laravel 12 support. Use the published release’s support/advisory information when choosing a deployment version; a development checkout, local doctor or passing SQLite suite does not certify every database, queue, scanner or live provider. Historical upgrade notes describe their named older versions, not a current support promise.

## Authority and sensitive data

The embedded host owns authentication, customer permissions and HTTP endpoints. Pass an existing client and live authorizer; null or a background principal is not permission. Business references identify external resources and do not authorize them. Provider accounts/senders stay owner-scoped and client credentials remain independently revocable. Keep provider credentials and callback secrets in trusted configuration/encrypted account fields, never in message input or logs.

Raw models, original/rendered bodies, recipient envelopes, metadata and retained attachments are sensitive internal records. Hosts authorize administrative reads/downloads and project only the necessary fields. Prepared-message metadata is validated and retained; callers must minimize sensitive data rather than assume universal redaction. The optional ordinary tracking/scheduling readers and privacy features have their own documented safe projections and retention policy.

Optional sensitive-array encryption does not encrypt all queryable scalar fields or every prepared-message body. Preserve required old transformer keys/profiles while protected history remains. Treat `UnreadableSensitiveDataException` as an operational incident; never reinterpret ciphertext as plaintext. Preview bounded anonymization/deletion before applying reviewed retention.

## Files, HTML and provider effects

Keep private Media bytes inaccessible anonymously, allowlist destination disks, bind real host permissions and deliberately choose scanner behavior. A no-op scanner is not malware protection. Authenticated/signed download routes must recheck current scope and byte lifecycle. Use supported localization and native Media operations; retain checksum/deletion evidence when bytes expire. Preserve URL address/redirect/size safeguards.

The service accepts prepared HTML/CSS/text. Hosts own trusted Mailable/Blade code; clients do not gain a server-side template/PHP execution API. Render private previews inside a restrictive sandbox/CSP without exposing untrusted markup to the operator application origin.

Retain client-scoped idempotency, commit boundaries, submission leases and provider receipts. An `unknown` outcome may already have reached the provider: reconcile before resending. Local acceptance or SMTP submission is not proof of recipient inbox delivery.

## Webhooks and callbacks

Verify original provider bytes with the configured account’s signature verifier before persisting verified receipts or normalizing delivery effects. Bound request sizes and replay/time windows. Do not trust an incoming assertion that a payload is already verified.

Outbound client callbacks use separate signing secrets, exact body bytes, timestamps and stable event IDs. Receivers verify client binding, signatures and freshness, persist/deduplicate before acknowledgement and make business side effects idempotent. Preserve public-HTTPS/DNS protections and disabled redirects. Local-origin exceptions belong only to explicit allowed development/test configurations. PHP tracking events, provider receipts and signed HTTP callbacks are separate protocols; their durability guarantees differ.
