<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Enums\EmailMessageType;
use Nvl\MailNotifications\Exceptions\ManagedDeliveryConflict;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\SenderIdentity;
use Nvl\MailNotifications\Services\Flags\EmailFlagger;
use Nvl\MailNotifications\Support\ManagedConfiguration;

/**
 * Persists a validated acceptance behind ManagedDelivery and its live authorizer.
 *
 * @internal
 *
 * @phpstan-import-type ManagedMessageInput from EmailContent
 */
class MessageAcceptor
{
    public function __construct(private EmailContent $content, private MessageDispatcher $dispatcher, private AttachmentLocalizer $attachments, private ProviderManager $providers, private MailTesting $testing, private ClientWebhookPublisher $clientWebhooks) {}

    /**
     * Accept validated input under live client authority, stage bytes safely and preserve idempotency across replay.
     *
     * @param  ManagedMessageInput  $input
     * @param  null|Closure(Client): void  $authorize  Recheck current caller authority inside acceptance, after staging.
     */
    public function accept(Client $client, array $input, string $idempotencyKey, ?Closure $authorize = null): EmailMessage
    {
        $this->authorizeClient($client, $authorize);
        $hash = $this->requestFingerprint($input);
        if ($existing = $client->messages()->where('idempotency_key', $idempotencyKey)->first()) {
            if (! hash_equals($existing->request_hash, $hash)) {
                throw new ManagedDeliveryConflict;
            }

            return $existing;
        }
        $staged = $this->attachments->stage($client, $input);
        $retained = false;
        try {
            $message = DB::connection(ManagedConfiguration::connection())->transaction(function () use ($client, $input, $idempotencyKey, $hash, $staged, $authorize, &$retained): EmailMessage {
                $client = Client::query()->lockForUpdate()->findOrFail($client->id);
                $this->authorizeClient($client, $authorize);
                if ($existing = $client->messages()->where('idempotency_key', $idempotencyKey)->first()) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        throw new ManagedDeliveryConflict;
                    }

                    return $existing;
                }
                $settings = $client->effectiveMailSettings();
                $account = ProviderAccount::query()->where('owner_id', $client->owner_id)->find($settings['provider_account_id']);
                if (! ($client->isActive() && $client->owner->isActive() && $account?->isActive())) {
                    throw new AuthorizationException('The client, owner and provider must be active.');
                }
                $identity = SenderIdentity::query()->where('owner_id', $client->owner_id)->where('provider_account_id', $account->id)->where('email', $settings['from_email']);
                if ($settings['sender_identity_id'] ?? null) {
                    $identity->whereKey($settings['sender_identity_id']);
                }
                $sender = $identity->first();
                if (! $sender || ! $sender->isVerified() || ! $settings['from_email']) {
                    throw ValidationException::withMessages(['sender_identity' => 'A verified sender on the effective provider account is required.']);
                }
                $content = $this->content->prepare($input);
                $interception = $this->testing->interception($input);
                $recipients = $interception === null ? $input : ['to' => [$interception['effective_recipient']]];
                $message = EmailMessage::create([
                    'client_id' => $client->id, 'provider_account_id' => $account->id, 'sender_identity_id' => $sender->id,
                    'type' => $input['type'] ?? EmailMessageType::Transactional->value,
                    'category' => $input['category'] ?? null, 'language' => isset($input['language']) ? mb_strtolower($input['language']) : null,
                    'status' => 'accepted', 'idempotency_key' => $idempotencyKey,
                    'request_hash' => $hash, 'from_email' => $settings['from_email'], 'from_name' => $settings['from_name'],
                    'reply_to_email' => $settings['reply_to_email'], 'reply_to_name' => $settings['reply_to_name'],
                    ...$content,
                    'original_subject' => $input['subject'],
                    'original_html' => $input['html'] ?? null,
                    'original_text' => $input['text'] ?? null,
                    'original_css' => $input['css'] ?? null,
                    'data' => $input['data'] ?? [], 'metadata' => $input['metadata'] ?? [], 'tags' => $input['tags'] ?? [],
                    'testing_interception' => $interception,
                    'external_reference' => $input['external_reference'] ?? null,
                    'send_at' => isset($input['send_at']) ? CarbonImmutable::parse($input['send_at'])->utc() : null,
                ]);
                foreach (['to', 'cc', 'bcc'] as $type) {
                    foreach ($recipients[$type] ?? [] as $recipient) {
                        $message->recipients()->create(['type' => $type, 'email' => mb_strtolower($recipient['email']), 'name' => $recipient['name'] ?? null, 'delivery_status' => 'pending']);
                    }
                }
                $message->references()->createMany(array_map(fn (array $reference): array => [
                    'client_id' => $client->id, 'type' => $reference['type'], 'reference_id' => $reference['id'],
                ], $input['references'] ?? []));
                $this->attachments->persist($message, $staged);
                app(EmailFlagger::class)->apply($message);
                $attempt = $message->attempts()->create(['provider_account_id' => $account->id, 'number' => 1, 'status' => 'pending']);
                $this->providers->providerFor($account)->validatePayload($message->load(['recipients', 'messageAssets']), $attempt);
                $this->clientWebhooks->publish($message, 'message.accepted', 'accepted:'.$message->id);
                $this->attachments->retained($staged);
                $retained = true;

                return $message;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $retained = false;
            $this->authorizeClient($client, $authorize);
            $message = $client->messages()->where('idempotency_key', $idempotencyKey)->first();
            if (! $message) {
                throw $exception;
            }
            if (! hash_equals($message->request_hash, $hash)) {
                throw new ManagedDeliveryConflict;
            }
        } catch (\Throwable $exception) {
            $retained = false;
            throw $exception;
        } finally {
            if (! $retained) {
                $this->attachments->cleanup($staged);
            }
        }
        $this->dispatcher->dispatchMessage($message->id);

        return $message->fresh() ?? throw new \RuntimeException('The accepted message is unavailable.');
    }

    /** Recheck authority and eligibility for every acceptance and idempotent replay. */
    private function authorizeClient(Client $client, ?Closure $authorize): void
    {
        $client->refresh()->load(['mailSettings', 'owner.mailSettings']);
        $authorize?->__invoke($client);
        $settings = $client->effectiveMailSettings();
        $account = ProviderAccount::query()->where('owner_id', $client->owner_id)->find($settings['provider_account_id']);
        if (! ($client->isActive() && $client->owner->isActive() && $account?->isActive())) {
            throw new AuthorizationException('The client, owner and provider must be active.');
        }
    }

    /**
     * Hash canonical submission meaning so equivalent defaults replay safely and changed content conflicts.
     *
     * @param  ManagedMessageInput  $input
     */
    public function requestFingerprint(array $input): string
    {
        if (($input['type'] ?? null) instanceof EmailMessageType) {
            $input['type'] = $input['type']->value;
        }
        if (($input['send_at'] ?? null) instanceof DateTimeInterface) {
            $input['send_at'] = $input['send_at']->format('Y-m-d\TH:i:s.uP');
        }
        if (($input['type'] ?? EmailMessageType::Transactional->value) === EmailMessageType::Transactional->value) {
            unset($input['type']);
        }
        foreach (['category', 'language'] as $field) {
            if (($input[$field] ?? null) === null) {
                unset($input[$field]);
            }
        }
        if (isset($input['language'])) {
            $input['language'] = mb_strtolower($input['language']);
        }
        if (($input['references'] ?? []) === []) {
            unset($input['references']);
        } else {
            usort($input['references'], fn (array $left, array $right): int => strcmp($left['type'], $right['type']) ?: strcmp($left['id'], $right['id']));
        }

        return hash('sha256', json_encode($this->canonicalize($this->fingerprintAttachments($input)), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function fingerprintAttachments(array $input): array
    {
        foreach (['attachments', 'inline_assets'] as $key) {
            $descriptors = $input[$key] ?? [];
            if (! is_array($descriptors)) {
                continue;
            }
            foreach ($descriptors as $index => $descriptor) {
                if (! is_array($descriptor)) {
                    continue;
                }
                if (($descriptor['file'] ?? null) instanceof UploadedFile) {
                    $file = $descriptor['file'];
                    $descriptor['file'] = ['sha256' => hash_file('sha256', $file->getRealPath()), 'name' => $file->getClientOriginalName(), 'size' => $file->getSize()];
                }
                if (isset($descriptor['content_base64']) && is_string($descriptor['content_base64'])) {
                    $descriptor['content_base64'] = ['sha256' => hash('sha256', $descriptor['content_base64'])];
                }
                $descriptors[$index] = $descriptor;
                $input[$key] = $descriptors;
            }
        }

        return $input;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonicalize(...), $value);
    }
}
