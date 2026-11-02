# Prepared-content delivery contract

Use `Nvl\MailNotifications\Services\ManagedDelivery`; the remote API SDK is documented in the separate `nvl/mail-notification-bridge` package. Full generated declarations are in the package's [public API reference](../resources/boost/skills/nvl-mail-notifications/references/api.md).

## Storage and authority prerequisites

The host provisions `Models\Owner`, `Models\Client`, `Models\ProviderAccount`, `Models\SenderIdentity`, owner defaults and a `ClientMailSettings` record through its own trusted workflow. A client belongs to one owner; accounts and senders must belong to that owner. Create static provider catalogue entries from the host database seeder with `Nvl\MailNotifications\Database\Seeders\IntegrationSeeder`; never use test factories to provision production credentials or mark an unverified API-provider sender as verified. Run the account's queued inventory synchronization before selecting its verified sender. SMTP identities require the host's explicit trusted verification workflow because SMTP has no provider inventory API.

Native Media is required. Configure the actual shared database connection, admitted private/public destination disks, safe disk delivery policies, nonempty cleanup roots and a deliberate scanner policy before enabling acceptance. Host Mailable source files may come from another private Laravel disk; preparation reads bounded bytes and acceptance creates a separate immutable mail snapshot. The package doctor checks local declarations, not actual malware scanning, bucket ACLs or real provider acceptance.

Bind the host's real `Nvl\Media\Contracts\MediaAuthorization` policy, then optionally compose the package's existing mail constraints in the application provider:

```php
use Nvl\MailNotifications\Services\Delivery\MailMediaAuthorization;
use Nvl\Media\Contracts\MediaAuthorization;

$app->extend(MediaAuthorization::class, static fn (MediaAuthorization $hostPolicy): MediaAuthorization => new MailMediaAuthorization($hostPolicy));
```

`$app` is the host container after its own policy has been bound. The decorator preserves fallback denials, reloads current mail ownership and rejects cross-client access and mutation of accepted attachment snapshots. It grants no implicit permission to administrators or `SystemActor::Background`. A custom policy may enforce these contracts itself; do not substitute a blanket allow policy. Hosts authenticate and authorize private downloads and stream bytes through their own route; this package installs no routes.

## Native acceptance

Configure `nvl-mail-notifications.managed.enabled=true`, enable a durable queue/worker, and provision an owner, client, provider account and sender in the host's authorized configuration workflow. The client must have its own `ClientMailSettings` record, with eligible owner defaults or explicit delivery overrides. The package supplies the models and resolver; the host owns administrative HTTP endpoints and credential issuance.

```php
use Illuminate\Auth\Access\AuthorizationException;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Services\ManagedDelivery;

$authorize = function (Client $current) use ($actor, $hostPolicy): void {
    if (! $hostPolicy->canSendForClient($actor, $current)) {
        throw new AuthorizationException;
    }
};

$message = app(ManagedDelivery::class)->send(
    $client,
    [
        'to' => [['email' => 'customer@example.com', 'name' => 'Customer']],
        'subject' => 'Order receipt',
        'html' => '<p>Thank you for your order.</p>',
        'text' => 'Thank you for your order.',
        'css' => 'p { color: #222; }',
        'type' => 'transactional',
        'category' => 'order.receipt',
        'language' => 'en',
        'metadata' => ['channel' => 'checkout'],
        'references' => [['type' => 'user', 'id' => '7'], ['type' => 'order', 'id' => '42']],
    ],
    'order:42:receipt:v1',
    $authorize,
);
```

`$actor` and `$hostPolicy` belong to your host application. The authorizer must check the current client supplied by the package rather than trusting a previously loaded relation. It can throw or return `false` to deny. When omitted, the host must authorize an existing authenticated actor through `nvl-send-managed-mail`. Null does not authorize background work. The returned `EmailMessage` is persisted acceptance, not a promise of inbox delivery.

Reusing the same client/key with equivalent input returns the same message after authority is checked again. Changed content raises `Exceptions\ManagedDeliveryConflict`. Preserve the prepared payload when a retryable host job contains dynamic links or timestamps. Keys are 1–191 printable non-space ASCII bytes and are unique within the persisted client; credential rotation does not create a new idempotency namespace. Validation failure writes no accepted message; the public boundary raises Laravel `ValidationException` and authorization failures raise `AuthorizationException`. Map those exceptions to your existing HTTP contract in the host.

## Fields and limits

| Field | Accepted meaning |
| --- | --- |
| `to`, `cc`, `bcc` | Recipient object lists; 1–50 To recipients and at most 10 each Cc/Bcc; no duplicate address across the envelope |
| `subject` | Required single-line subject, at most 998 characters |
| `html`, `text`, `css` | Supplied content, at least one body; CSS requires HTML; 1,000,000-character body and 100,000-character CSS bounds |
| `type` | `transactional` or `marketing`; persisted as `Enums\EmailMessageType` |
| `category`, `language` | Optional classification alias and BCP-47-style language identifier; neither renders/translates content |
| `metadata`, `tags` | Bounded structured application context and up to four user tags; transport-reserved tags remain protected |
| `references` | Up to 20 `{type,id}` business references, scoped by the authenticated/persisted client; IDs have at most 191 characters |
| `external_reference` | Optional single external correlation value, retained for compatibility |
| `attachments`, `inline_assets` | Up to ten each; localized private bytes with size, checksum and disposition evidence |
| `send_at` | Optional native date or date string for deferred dispatch, within the one-year future bound |
| `data` | Retained caller data; it is not a template-variable execution contract |

Native collections for recipients, attachments, inline parts, tags and references are lists. Uploaded files are Laravel `UploadedFile` instances, not arbitrary file objects. Native arrays must be non-recursive and at most 128 levels deep. Native calls also accept `EmailMessageType` and `DateTimeInterface`; a native date fingerprints its `Y-m-d\TH:i:s.uP` representation, while an existing HTTP date string keeps its original fingerprint bytes.

The exact validated array shape is declared by `Services\Delivery\EmailContent::ManagedMessageInput` and `MessageInputValidator`. Unknown/protected authority fields are rejected. Sender/account ownership is resolved from stored settings, not caller-supplied provider credentials. Original subject/body/CSS remain available alongside prepared delivery content. No template IDs or rendering endpoints are provided.

## Attachments and native Mailables

Supply one supported source per descriptor, such as `content_base64`, a validated HTTPS URL, an allowed uploaded file, or an existing client asset. Retained-byte bounds are 10 MiB per file and 20 MiB aggregate; provider wire-size limits are checked separately. URLs are subject to address, redirect and size safeguards. Inline parts carry a unique `content_id`. The host owns multipart parsing and its normal request limits.

The following host Mailable uses ordinary Laravel translation keys and a private disk. The host supplies `mail.order_receipt` and `mail.receipt_body` translations in its `en`/`bg` language files and authenticates the order before choosing its receipt path.

For example, `lang/en/mail.php`:

<!-- example:translations-en -->
```php
return [
    'order_receipt' => 'Order :number receipt',
    'receipt_body' => 'Thank you for your order.',
];
```

And `lang/bg/mail.php`:

<!-- example:translations-bg -->
```php
return [
    'order_receipt' => 'Разписка за поръчка :number',
    'receipt_body' => 'Благодарим за поръчката.',
];
```

<!-- example:order-receipt-mailable -->
```php
use Illuminate\Mail\Mailable;
use Nvl\MailNotifications\Laravel\Concerns\SubmitsMailDelivery;

final class OrderReceipt extends Mailable
{
    use SubmitsMailDelivery;

    public function __construct(public string $orderNumber, public string $receiptPath) {}

    public function build(): static
    {
        return $this->subject(__('mail.order_receipt', ['number' => $this->orderNumber]))
            ->html('<p>'.e(__('mail.receipt_body')).'</p>')
            ->attachFromStorageDisk('private', $this->receiptPath, 'receipt.pdf', ['mime' => 'application/pdf']);
    }
}
```

For one-step acceptance, reuse the live `$authorize` callback from the native example above:

```php
$mail = (new OrderReceipt('42', 'orders/42.pdf'))->to('customer@example.com');
$options = [
    'language' => 'bg',
    'references' => [['type' => 'user', 'id' => '7'], ['type' => 'order', 'id' => '42']],
];
$message = $mail->submitMailDelivery($client, 'order:42:receipt:bg:v1', $options, $authorize);
```

To reuse exactly the same prepared input between preview and acceptance, prepare once and submit that array:

```php
use Nvl\MailNotifications\Services\ManagedDelivery;

$prepared = $mail->toMailDelivery($options);
// Show the prepared source HTML in the host's protected, sandboxed preview.
$message = app(ManagedDelivery::class)->send($client, $prepared, 'order:42:receipt:bg:v1', $authorize);
```

The helper options are `type`, `category`, `language`, `data`, `metadata`, `tags`, `external_reference`, `references`, `send_at` and `css`; envelope/body/file overrides are not preparation options.

Payload parity is not a pixel-identical provider-rendering guarantee. Acceptance applies CSS inlining, resolves current sender settings and snapshots private bytes. A source-HTML preview must account for that CSS/CID pipeline before presenting itself as the final email. Inspect stored `EmailMessage::html`, `text`, sender and attachment evidence after acceptance for the frozen result; host preview routes still require authorization and a restrictive sandbox/CSP. Email-client rendering remains external.

These are two alternative submission patterns for the same email. Do not execute both. Calling `toMailDelivery()` and then `submitMailDelivery()` renders twice; dynamic links, timestamps or changing source files may differ. Preserve the already prepared payload and idempotency key across retryable host work, and keep its private bodies/attachment bytes out of logs. A draft preview is not acceptance and cannot prove later provider delivery. In the raw array API `language` classifies already rendered content; the Mailable helper additionally selects Laravel's render locale and restores the caller's locale afterward.


The trait clones and renders through an isolated array transport. It does not use the host's configured SMTP transport or execute delivery/tracking listeners. From and Reply-To remain service-configured. Custom Message-ID, References, In-Reply-To, Sender, Return-Path, priority, arbitrary headers and non-UTF-8 body charsets fail explicitly. Only the supported envelope, subject and Laravel tag/metadata headers are represented. Explicit preparation metadata/tags replace the Mailable values. Laravel-generated random CIDs are normalized to deterministic identifiers for stable replay; explicitly supplied CIDs remain intact. Host templates/Blade/Markdown stay in the host; this package receives the rendered content. Use the separate bridge trait `SubmitsMailNotifications` when the host calls a remote service rather than embedding this engine.

Marketing preferences only affect marketing messages; provider hard suppressions remain separate. Language is metadata describing content already rendered by the caller. Business references are opaque external identities, not polymorphic database joins to another application.
