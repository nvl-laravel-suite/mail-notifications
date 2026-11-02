<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Closure;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator as Validation;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use JsonException;
use Nvl\MailNotifications\Enums\EmailMessageType;
use Nvl\MailNotifications\Services\Delivery\EmailContent;

/**
 * Shared HTTP-independent validation for the managed delivery boundary.
 *
 * @phpstan-import-type ManagedMessageInput from EmailContent
 *
 * @api
 */
class MessageInputValidator
{
    /**
     * Validate without trimming, coercing or defaulting payload values.
     *
     * @param  array<string, mixed>  $input
     * @return ManagedMessageInput
     */
    public function validate(array $input, string $idempotencyKey): array
    {
        $this->assertInput($input, $idempotencyKey);

        return $input;
    }

    /**
     * Declare the shared HTTP/native prepared-mail schema, including protected-field rejection and bounded attachments.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(EmailMessageType::class)],
            'category' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z][a-z0-9._-]{0,99}\z/D'],
            'language' => ['nullable', 'string', 'max:35', 'regex:/\A[a-z]{2,8}(?:-[a-z0-9]{1,8})*\z/iD'],
            'to' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'to.*' => ['array:email,name'],
            'to.*.email' => ['required', 'email:rfc', 'max:254'],
            'to.*.name' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'cc' => ['sometimes', 'array', 'list', 'max:10'],
            'cc.*' => ['array:email,name'],
            'cc.*.email' => ['required', 'email:rfc', 'max:254'],
            'cc.*.name' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'bcc' => ['sometimes', 'array', 'list', 'max:10'],
            'bcc.*' => ['array:email,name'],
            'bcc.*.email' => ['required', 'email:rfc', 'max:254'],
            'bcc.*.name' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'template_key' => ['missing'],
            'template_version' => ['missing'],
            'composition_revision_id' => ['missing'],
            'composition_render_id' => ['missing'],
            'locale' => ['missing'],
            'data' => ['sometimes', 'array', 'max:100'],
            'subject' => ['required', 'string', 'max:998', 'not_regex:/[\r\n]/'],
            'html' => ['nullable', 'string', 'max:1000000', 'required_without:text'],
            'text' => ['nullable', 'string', 'max:1000000', 'required_without:html'],
            'css' => ['nullable', 'string', 'max:100000'],
            'attachments' => ['sometimes', 'array', 'list', 'max:10'],
            'attachments.*' => ['required', $this->attachmentRule(false)],
            'inline_assets' => ['sometimes', 'array', 'list', 'max:10'],
            'inline_assets.*' => ['required', $this->attachmentRule(true)],
            'metadata' => ['sometimes', 'array', 'max:30'],
            'tags' => ['sometimes', 'array', 'list', 'max:4'],
            'tags.*' => ['required', 'string', 'max:150', 'distinct', 'not_regex:/^attempt-/'],
            'external_reference' => ['nullable', 'string', 'max:191'],
            'references' => ['sometimes', 'array', 'list', 'max:20'],
            'references.*' => ['required', 'array:type,id'],
            'references.*.type' => ['required', 'string', 'regex:/\A[a-z][a-z0-9._-]{0,63}\z/D'],
            'references.*.id' => ['required', 'string', 'max:191', 'regex:/\A[^\s\x00-\x1f\x7f](?:[^\x00-\x1f\x7f]*[^\s\x00-\x1f\x7f])?\z/uD'],
            'send_at' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) && ! $value instanceof DateTimeInterface) {
                    $fail('The send at field must be a date string or a native date object.');
                }
            }, 'date', 'before:'.now()->addYear()->toIso8601String()],
        ];
    }

    /**
     * Establish the complete native shape through runtime validation, preserving original values.
     *
     * @param  array<string, mixed>  $input
     *
     * @phpstan-assert ManagedMessageInput $input
     */
    private function assertInput(array $input, string $idempotencyKey): void
    {
        if (! $this->boundedArrays($input)) {
            throw ValidationException::withMessages(['input' => 'Managed input must not contain recursive arrays or exceed 128 nesting levels.']);
        }
        $validator = Validation::make($input, $this->rules());
        $validator->after($this->after($input, $idempotencyKey));
        $validator->after(function (Validator $validator) use ($input): void {
            foreach (array_diff(array_keys($input), array_keys(array_filter($this->rules(), static fn (string $key): bool => ! str_contains($key, '.'), ARRAY_FILTER_USE_KEY))) as $key) {
                $validator->errors()->add((string) $key, 'This field is not supported for managed delivery.');
            }
        });

        $validator->validate();
    }

    private function attachmentRule(bool $inline): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($inline): void {
            if (is_string($value) && ! $inline) {
                if (! Str::isUuid($value)) {
                    $fail('The catalog attachment must be a UUID.');
                }

                return;
            }
            if (! is_array($value)) {
                $fail('An attachment must be a catalog UUID or an object.');

                return;
            }
            $sources = array_intersect(['file', 'content_base64', 'url', 'asset_id'], array_keys($value));
            if (count($sources) !== 1 || array_diff(array_keys($value), ['file', 'content_base64', 'url', 'asset_id', 'name', 'disposition', 'content_id']) !== []) {
                $fail('Provide exactly one attachment source and supported metadata.');

                return;
            }
            if (array_key_exists('file', $value) && ! $value['file'] instanceof UploadedFile) {
                $fail('Attachment files must be Laravel uploaded files.');

                return;
            }
            if (array_key_exists('disposition', $value) && ! is_string($value['disposition'])) {
                $fail('Attachment dispositions must be strings.');

                return;
            }
            $validation = Validation::make($value, [
                'file' => ['sometimes', 'required', 'file', 'max:10240'],
                'content_base64' => ['sometimes', 'required', 'string', 'max:13981016'],
                'url' => ['sometimes', 'required', 'string', 'max:2048', 'url:https'],
                'asset_id' => ['sometimes', 'required', 'uuid'],
                'name' => ['sometimes', 'required', 'string', 'max:255', 'not_regex:/[\\\\\/\r\n\x00-\x1f]/'],
                'disposition' => ['sometimes', 'required', 'string', 'in:attachment,inline'],
                'content_id' => [$inline || ($value['disposition'] ?? null) === 'inline' ? 'required' : 'nullable', 'string', 'max:191', 'regex:/\A[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\z/'],
            ]);
            if ($inline && ($value['disposition'] ?? 'inline') !== 'inline') {
                $fail('Inline assets must have inline disposition.');
            }
            if ($validation->fails()) {
                $fail($validation->errors()->first());
            }
        };
    }

    /** Guard PHP-only recursive input before Laravel recursively parses arrays. */
    private function boundedArrays(mixed $value, int $depth = 0): bool
    {
        if ($depth > 128) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! $this->boundedArrays($item, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && (! is_string($value) || trim($value) !== '');
    }

    private function isJsonValue(mixed $value, int $depth = 0): bool
    {
        if ($depth > 128) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! $this->isJsonValue($item, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_string($value) || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value));
    }

    /**
     * Apply cross-field checks for bodies, recipients, references and attachment descriptors after base validation.
     *
     * @param  array<string, mixed>  $input
     * @return list<Closure(Validator): void>
     */
    public function after(array $input, string $idempotencyKey): array
    {
        return [function (Validator $validator) use ($input, $idempotencyKey): void {
            if (! preg_match('/\A[\x21-\x7e]{1,191}\z/', $idempotencyKey)) {
                $validator->errors()->add('idempotency_key', 'A printable Idempotency-Key header of at most 191 characters is required.');
            }
            if ($this->filled($input['css'] ?? null) && ! $this->filled($input['html'] ?? null)) {
                $validator->errors()->add('css', 'CSS requires an HTML body.');
            }
            $references = $input['references'] ?? [];
            if (is_array($references) && count($references) <= 20) {
                $seen = [];
                foreach ($references as $reference) {
                    if (! is_array($reference) || ! is_string($reference['type'] ?? null) || ! is_string($reference['id'] ?? null)) {
                        continue;
                    }
                    $identity = $reference['type'].':'.$reference['id'];
                    if (isset($seen[$identity])) {
                        $validator->errors()->add('references', 'Each business reference may appear only once.');
                        break;
                    }
                    $seen[$identity] = true;
                }
            }
            $payloadValues = array_intersect_key($input, array_flip(['metadata', 'data']));
            $validJsonValues = true;
            foreach ($payloadValues as $field => $value) {
                if (! $this->isJsonValue($value)) {
                    $validator->errors()->add($field, 'Data and metadata may contain only JSON values, without uploaded files or objects.');
                    $validJsonValues = false;
                }
            }
            if ($validJsonValues) {
                try {
                    if (strlen(json_encode($payloadValues, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)) > 65536) {
                        $validator->errors()->add('data', 'Data and metadata must not exceed 64 KiB.');
                    }
                } catch (JsonException) {
                    $validator->errors()->add('data', 'Data and metadata must be bounded JSON values.');
                }
            }
            $contentIds = [];
            foreach (['attachments', 'inline_assets'] as $key) {
                foreach ((array) ($input[$key] ?? []) as $attachment) {
                    if (is_array($attachment) && isset($attachment['content_id'])) {
                        if (in_array($attachment['content_id'], $contentIds, true)) {
                            $validator->errors()->add($key, 'Content IDs must be unique across the message.');
                        }
                        $contentIds[] = $attachment['content_id'];
                    }
                }
            }
            $addresses = [];
            foreach (['to', 'cc', 'bcc'] as $type) {
                foreach ((array) ($input[$type] ?? []) as $recipient) {
                    if (! is_array($recipient) || ! isset($recipient['email']) || ! is_string($recipient['email'])) {
                        continue;
                    }
                    $email = mb_strtolower($recipient['email']);
                    if (in_array($email, $addresses, true)) {
                        $validator->errors()->add($type, 'Each recipient may appear only once in the envelope.');
                    }
                    $addresses[] = $email;
                }
            }
        }];
    }
}
