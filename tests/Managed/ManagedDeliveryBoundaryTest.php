<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Enums\EmailMessageType;
use Nvl\MailNotifications\Exceptions\ManagedDeliveryConflict;
use Nvl\MailNotifications\Jobs\SendEmailMessage;
use Nvl\MailNotifications\Laravel\Concerns\SubmitsMailDelivery;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\SenderIdentity;
use Nvl\MailNotifications\Models\StagedMailFile;
use Nvl\MailNotifications\Services\Delivery\MailTesting;
use Nvl\MailNotifications\Services\ManagedDelivery;
use Nvl\MailNotifications\Services\MessageInputValidator;
use Symfony\Component\HttpFoundation\File\File;

function managedBoundaryClient(): Client
{
    $account = ProviderAccount::factory()->create();
    $sender = SenderIdentity::factory()->for($account)->create(['email' => 'sender@example.com']);

    return Client::factory()->withMailSettings(['provider_account_id' => $account->id, 'sender_identity_id' => $sender->id, 'from_email' => $sender->email])->create(['owner_id' => $account->owner_id]);
}

it('rejects malformed native submissions before rows files or delivery jobs', function (array $changes, string $key) {
    Queue::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    $client = managedBoundaryClient();
    $input = array_replace(['to' => [['email' => 'target@example.com']], 'subject' => 'Subject', 'text' => 'Body'], $changes);

    expect(fn () => app(ManagedDelivery::class)->send($client, $input, $key, static fn (Client $current): bool => true))->toThrow(ValidationException::class);

    $this->assertDatabaseCount((new EmailMessage)->getTable(), 0);
    $this->assertDatabaseCount((new StagedMailFile)->getTable(), 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
})->with([
    'bad idempotency key' => [[], "bad\nkey"],
    'protected owner' => [['owner_id' => 'injected'], 'key'],
    'duplicate envelope' => [['cc' => [['email' => 'TARGET@example.com']]], 'key'],
    'no body' => [['text' => null], 'key'],
    'css without html' => [['css' => 'p { color: red; }'], 'key'],
    'object metadata' => [['metadata' => ['object' => new stdClass]], 'key'],
    'too much body' => [['text' => str_repeat('x', 1000001)], 'key'],
    'duplicate reference' => [['references' => [['type' => 'invoice', 'id' => '42'], ['type' => 'invoice', 'id' => '42']]], 'key'],
    'malformed attachment' => [['attachments' => [['content_base64' => 'abc', 'url' => 'https://host.test/file']]], 'key'],
]);

it('denies native managed calls without an actor even if a guest gate is permissive', function () {
    Queue::fake();
    $client = managedBoundaryClient();
    Gate::define('nvl-send-managed-mail', static fn ($actor = null): bool => true);

    expect(fn () => app(ManagedDelivery::class)->send($client, ['to' => [['email' => 'target@example.com']], 'subject' => 'Subject', 'text' => 'Body'], 'denied'))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount((new EmailMessage)->getTable(), 0);
    Queue::assertNothingPushed();
});

it('reuses idempotency across canonical defaults and rechecks live authorizer on replay', function () {
    Queue::fake();
    $client = managedBoundaryClient();
    $input = ['to' => [['email' => 'target@example.com']], 'subject' => '  Subject  ', 'text' => "  Body\n", 'language' => 'EN'];
    $delivery = app(ManagedDelivery::class);
    $allow = static fn (Client $current): bool => true;

    $first = $delivery->send($client, $input, 'stable', $allow);
    $again = $delivery->send($client, [...$input, 'type' => 'transactional', 'category' => null, 'language' => 'en', 'references' => []], 'stable', $allow);

    expect($again->id)->toBe($first->id);
    expect($first->original_subject)->toBe('  Subject  ');
    expect($first->original_text)->toBe("  Body\n");
    expect(fn () => $delivery->send($client, $input, 'stable', static fn (Client $current): bool => false))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount((new EmailMessage)->getTable(), 1);
});

it('checks persisted eligibility instead of stale in-memory client relations', function () {
    Queue::fake();
    $client = managedBoundaryClient();
    $client->load(['owner', 'mailSettings']);
    $client->mailSettings->update(['status' => 'suspended']);

    expect(fn () => app(ManagedDelivery::class)->send($client, ['to' => [['email' => 'target@example.com']], 'subject' => 'Subject', 'text' => 'Body'], 'inactive', static fn (Client $current): bool => true))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount((new EmailMessage)->getTable(), 0);
});

it('preserves null omission and bytes in shared validation for HTTP and native consumers', function () {
    $input = ['to' => [['email' => 'target@example.com', 'name' => null]], 'subject' => ' Subject ', 'text' => " Text\n", 'html' => null, 'metadata' => ['explicit' => null], 'category' => null];

    $validated = app(MessageInputValidator::class)->validate($input, 'bytes');

    expect($validated)->toEqual($input);
    expect(array_key_exists('type', $validated))->toBeFalse();
});

it('submits an ordinary mailable through current client sender settings and the managed queue', function () {
    Queue::fake();
    $client = managedBoundaryClient();
    $mail = new class extends Mailable
    {
        use SubmitsMailDelivery;
    };
    $mail->to('target@example.com')->from('ignored@example.com')->replyTo('ignored-reply@example.com')->subject('Host subject')->html('<p>Host body</p>');

    $message = $mail->submitMailDelivery($client, 'mailable-submit', [], static fn (Client $current): bool => true);

    expect($message->from_email)->toBe('sender@example.com');
    expect($message->reply_to_email)->toBe($client->effectiveMailSettings()['reply_to_email']);
    expect($message->original_html)->toBe('<p>Host body</p>');
    expect($mail->from[0]['address'])->toBe('ignored@example.com');
    $this->assertDatabaseCount((new EmailMessage)->getTable(), 1);
    Queue::assertPushed(SendEmailMessage::class);
});

it('allows an existing authenticated actor through the explicit host gate', function () {
    Queue::fake();
    $client = managedBoundaryClient();
    $this->actingAs($client);
    Gate::define('nvl-send-managed-mail', static fn (Client $actor, Client $target): bool => $actor->id === $target->id);

    $message = app(ManagedDelivery::class)->send($client, ['to' => [['email' => 'target@example.com']], 'subject' => 'Gate subject', 'text' => 'Body'], 'gate-authorized');

    expect($message->client_id)->toBe($client->id);
    $this->assertDatabaseCount((new EmailMessage)->getTable(), 1);
});

it('rejects recursive local metadata without recursion exhaustion or writes', function () {
    Queue::fake();
    $client = managedBoundaryClient();
    $metadata = [];
    $metadata['self'] = &$metadata;
    $input = ['to' => [['email' => 'target@example.com']], 'subject' => 'Subject', 'text' => 'Body', 'metadata' => $metadata];

    expect(fn () => app(ManagedDelivery::class)->send($client, $input, 'recursive', static fn (Client $current): bool => true))->toThrow(ValidationException::class);
    $this->assertDatabaseCount((new EmailMessage)->getTable(), 0);
    Queue::assertNothingPushed();
});

it('reports native idempotency conflicts without selecting an HTTP status or changing accepted intent', function (): void {
    Queue::fake();
    $client = managedBoundaryClient();
    $delivery = app(ManagedDelivery::class);
    $allow = static fn (Client $current): bool => true;
    $input = ['to' => [['email' => 'target@example.com']], 'subject' => 'Original', 'text' => 'Original body'];
    $accepted = $delivery->send($client, $input, 'native-conflict', $allow);

    try {
        $delivery->send($client, [...$input, 'subject' => 'Changed'], 'native-conflict', $allow);
        $this->fail('Changed input under an accepted idempotency key must conflict.');
    } catch (ManagedDeliveryConflict $exception) {
        expect($exception->getCode())->toBe(0);
        expect($exception->getMessage())->toBe('The idempotency key was already used for a different request.');
    }
    expect($accepted->fresh()->original_subject)->toBe('Original');
    $this->assertDatabaseCount((new EmailMessage)->getTable(), 1);
    Queue::assertPushed(SendEmailMessage::class, 1);
});

it('rejects associative envelope attachment and tag collections at the public native boundary', function (string $field, array $items): void {
    $input = ['to' => [['email' => 'target@example.com']], 'subject' => 'Subject', 'text' => 'Body', $field => $items];

    expect(fn () => app(MessageInputValidator::class)->validate($input, 'associative'))->toThrow(ValidationException::class);
})->with([
    'to' => ['to', ['target' => ['email' => 'target@example.com']]],
    'cc' => ['cc', ['target' => ['email' => 'copy@example.com']]],
    'bcc' => ['bcc', ['target' => ['email' => 'blind@example.com']]],
    'attachments' => ['attachments', ['file' => ['content_base64' => base64_encode('bytes'), 'name' => 'file.txt']]],
    'inline assets' => ['inline_assets', ['logo' => ['content_base64' => base64_encode('bytes'), 'name' => 'logo.txt', 'content_id' => 'logo@host.test']]],
    'tags' => ['tags', ['category' => 'invoice']],
]);

it('rejects generic Symfony files and unknown upload objects as native attachment files', function (string $kind): void {
    $path = tempnam(sys_get_temp_dir(), 'managed-invalid-upload-');
    file_put_contents($path, 'bytes');
    $file = $kind === 'Symfony file' ? new File($path) : new stdClass;
    $input = ['to' => [['email' => 'target@example.com']], 'subject' => 'Subject', 'text' => 'Body', 'attachments' => [['file' => $file]]];

    try {
        expect(fn () => app(MessageInputValidator::class)->validate($input, 'invalid-file'))->toThrow(ValidationException::class);
    } finally {
        unlink($path);
    }
})->with(['Symfony file', 'unknown object']);

it('rejects numeric and unsupported object scheduling inputs', function (mixed $schedule): void {
    $input = ['to' => [['email' => 'target@example.com']], 'subject' => 'Subject', 'text' => 'Body', 'send_at' => $schedule];

    expect(fn () => app(MessageInputValidator::class)->validate($input, 'invalid-schedule'))->toThrow(ValidationException::class);
})->with(['integer' => 1234567890, 'unknown object' => new stdClass]);

it('accepts native enum and date inputs without mutation and replays their stable identity', function (): void {
    Queue::fake();
    $this->freezeTime();
    $client = managedBoundaryClient();
    $sendAt = new DateTimeImmutable('+1 hour');
    $input = ['to' => [['email' => 'target@example.com']], 'subject' => 'Native values', 'text' => 'Body', 'type' => EmailMessageType::Transactional, 'send_at' => $sendAt];
    $service = app(ManagedDelivery::class);
    $allow = static fn (Client $current): bool => true;

    $first = $service->send($client, $input, 'native-values', $allow);
    $stringInput = $input;
    unset($stringInput['type']);
    $stringInput['send_at'] = $sendAt->format('Y-m-d\TH:i:s.uP');
    $again = $service->send($client, $stringInput, 'native-values', $allow);
    $explicitDefault = $service->send($client, [...$stringInput, 'type' => 'transactional'], 'native-values', $allow);

    expect($again->id)->toBe($first->id);
    expect($explicitDefault->id)->toBe($first->id);
    expect($first->type)->toBe(EmailMessageType::Transactional);
    expect($first->send_at->getTimestamp())->toBe($sendAt->getTimestamp());
    expect(app(MessageInputValidator::class)->validate($input, 'native-values')['send_at'])->toBe($sendAt);
    $this->assertDatabaseCount((new EmailMessage)->getTable(), 1);
});

it('rejects non-string disposition objects without coercion or framework type errors', function (): void {
    $input = ['to' => [['email' => 'target@example.com']], 'subject' => 'Subject', 'text' => 'Body', 'attachments' => [['content_base64' => base64_encode('bytes'), 'disposition' => new stdClass]]];

    expect(fn () => app(MessageInputValidator::class)->validate($input, 'invalid-disposition'))->toThrow(ValidationException::class);
});

it('rejects new prepared content when the package or delivery capability is disabled', function (string $key): void {
    Queue::fake();
    $client = managedBoundaryClient();
    config([$key => false]);

    expect(fn () => app(ManagedDelivery::class)->send($client, ['to' => [['email' => 'customer@example.test']], 'subject' => 'Paused', 'text' => 'Body'], 'disabled', static fn (): bool => true))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount((new EmailMessage)->getTable(), 0);
    Queue::assertNothingPushed();
})->with(['nvl-mail-notifications.enabled', 'nvl-mail-notifications.managed.enabled']);

it('uses package testing settings without requiring host mail configuration', function (): void {
    Queue::fake();
    $client = managedBoundaryClient();
    config(['mail.testing' => null, 'nvl-mail-notifications.testing' => [
        'enabled' => true, 'to_address' => 'qa@example.test', 'to_name' => 'QA Inbox',
        'respect_environment' => true, 'environments' => ['testing'],
    ]]);

    $message = app(ManagedDelivery::class)->send($client, [
        'to' => [['email' => 'customer@example.test']], 'cc' => [['email' => 'copy@example.test']],
        'bcc' => [['email' => 'blind@example.test']], 'subject' => 'Safe testing', 'text' => 'Body',
    ], 'package-testing', static fn (): bool => true);

    expect($message->recipients()->pluck('email')->all())->toBe(['qa@example.test'])
        ->and($message->testing_interception['original_recipients']['to'][0]['email'])->toBe('customer@example.test')
        ->and($message->testing_interception['original_recipients']['cc'][0]['email'])->toBe('copy@example.test')
        ->and($message->testing_interception['original_recipients']['bcc'][0]['email'])->toBe('blind@example.test');
});

it('retains an explicit host testing configuration ahead of package defaults', function (): void {
    config(['mail.testing' => ['enabled' => false], 'nvl-mail-notifications.testing' => [
        'enabled' => true, 'to_address' => 'qa@example.test', 'to_name' => 'QA',
    ]]);
    expect(app(MailTesting::class)->interception([
        'to' => [['email' => 'customer@example.test']], 'subject' => 'Host settings', 'text' => 'Body',
    ]))->toBeNull();
});
