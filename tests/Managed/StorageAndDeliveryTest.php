<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Jobs\DeliverClientWebhook;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\ClientWebhookDelivery;
use Nvl\MailNotifications\Models\ClientWebhookEndpoint;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\MarketingOptOut;
use Nvl\MailNotifications\Models\Owner;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\SenderIdentity;
use Nvl\MailNotifications\Models\SendingDomain;
use Nvl\MailNotifications\Services\Delivery\ClientWebhookPublisher;
use Nvl\MailNotifications\Services\Delivery\ClientWebhookSender;
use Nvl\MailNotifications\Services\Delivery\MessageAcceptor;
use Nvl\MailNotifications\Services\Delivery\MessageSender;
use Nvl\MailNotifications\Services\Delivery\PublicAddressResolver;
use Nvl\MailNotifications\Services\MailSettingsResolver;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Nvl\Support\Schema\SchemaIdentities;
use Nvl\Support\Schema\SchemaShape;

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
});

function managedProofClient(): Client
{
    $account = ProviderAccount::factory()->create();
    $sender = SenderIdentity::factory()->for($account)->create(['email' => 'sender@example.com']);

    return Client::factory()->withMailSettings(['provider_account_id' => $account->id, 'sender_identity_id' => $sender->id, 'from_email' => $sender->email])->create(['owner_id' => $account->owner_id]);
}

test('fresh managed storage preserves unrelated host owners clients and users', function (): void {
    Schema::create('owners', fn (Blueprint $table) => $table->string('host_name'));
    Schema::create('clients', fn (Blueprint $table) => $table->string('host_name'));
    DB::table('owners')->insert(['host_name' => 'Host owner']);
    DB::table('clients')->insert(['host_name' => 'Host client']);
    $client = managedProofClient();

    expect($client->getTable())->toBe('nvl_mail_notifications_clients');
    expect(Owner::query()->count())->toBe(1);
    expect(DB::table('owners')->sole()->host_name)->toBe('Host owner');
    expect(DB::table('clients')->sole()->host_name)->toBe('Host client');
    expect(Schema::hasColumn('users', 'is_admin'))->toBeFalse();
});

test('mapped legacy inventory tables keep configured joins search and verification', function (): void {
    $logicalTables = ['owners', 'owner_mail_settings', 'clients', 'client_mail_settings', 'provider_accounts', 'sending_domains', 'sender_identities'];
    foreach ($logicalTables as $logical) {
        Schema::rename(ManagedConfiguration::table($logical), $logical);
        config()->set('nvl-mail-notifications.tables.'.$logical, $logical);
    }
    $client = managedProofClient();
    $domain = SendingDomain::factory()->create(['owner_id' => $client->owner_id, 'provider_account_id' => $client->mailSettings->provider_account_id, 'domain' => 'example.com', 'status' => 'verified', 'verified_at' => now()]);
    $sender = SenderIdentity::findOrFail($client->mailSettings->sender_identity_id);
    $sender->update(['sending_domain_id' => $domain->id, 'verification_source' => 'domain']);

    expect(SenderIdentity::query()->verified()->matchingSearch('SENDER@EXAMPLE')->sole()->id)->toBe($sender->id);
    expect(SendingDomain::query()->matchingSearch('EXAMPLE')->sole()->id)->toBe($domain->id);
    expect($client->fresh()->effectiveMailSettings()['from_email'])->toBe('sender@example.com');
});

test('managed acceptance localizes immutable attachments and references before provider submission', function (): void {
    $client = managedProofClient();
    $message = app(MessageAcceptor::class)->accept($client, [
        'to' => [['email' => 'reader@example.com']], 'subject' => 'Invoice', 'text' => 'Accepted snapshot',
        'references' => [['type' => 'invoice', 'id' => '42']],
        'attachments' => [['content_base64' => base64_encode('Invoice bytes'), 'name' => 'invoice.txt']],
    ], 'snapshot');
    $asset = $message->messageAssets()->sole();
    expect(Storage::disk($asset->disk)->get($asset->path))->toBe('Invoice bytes');
    expect($asset->owns_file)->toBeTrue();
    expect($message->references()->sole()->reference_id)->toBe('42');
    Http::fake(['api.mailersend.com/v1/email' => Http::response([], 202, ['x-message-id' => 'provider-invoice'])]);
    app(MessageSender::class)->send($message->id);
    expect($message->fresh()->status)->toBe('submitted');
    Http::assertSent(fn ($request): bool => $request['attachments'][0]['content'] === base64_encode('Invoice bytes'));
});

test('client marketing opt outs block marketing while retaining transactional delivery', function (): void {
    $client = managedProofClient();
    MarketingOptOut::factory()->for($client)->create(['email' => 'reader@example.com']);
    $acceptor = app(MessageAcceptor::class);
    $input = ['to' => [['email' => 'reader@example.com']], 'subject' => 'News', 'text' => 'Body'];
    $marketing = $acceptor->accept($client, [...$input, 'type' => 'marketing'], 'marketing');
    $transactional = $acceptor->accept($client, $input, 'transactional');
    Http::fake(['api.mailersend.com/v1/email' => Http::response([], 202, ['x-message-id' => 'provider-transactional'])]);
    app(MessageSender::class)->send($marketing->id);
    app(MessageSender::class)->send($transactional->id);

    expect($marketing->fresh()->status)->toBe('blocked');
    expect($transactional->fresh()->status)->toBe('submitted');
    Http::assertSentCount(1);
});

test('callback outbox snapshots references once and signs exact persisted bytes', function (): void {
    config()->set('queue.default', 'database');
    $message = EmailMessage::factory()->create();
    $message->references()->create(['client_id' => $message->client_id, 'type' => 'invoice', 'reference_id' => '42']);
    ClientWebhookEndpoint::factory()->create(['client_id' => $message->client_id, 'url' => 'https://consumer.example/events', 'events' => ['message.accepted']]);
    app(ClientWebhookPublisher::class)->publish($message, 'message.accepted', 'accepted:'.$message->id);
    app(ClientWebhookPublisher::class)->publish($message, 'message.accepted', 'accepted:'.$message->id);
    $delivery = ClientWebhookDelivery::query()->sole();
    expect(json_decode($delivery->payload, true)['data']['references'])->toBe([['type' => 'invoice', 'id' => '42']]);
    app()->instance(PublicAddressResolver::class, Mockery::mock(PublicAddressResolver::class, function ($mock): void {
        $mock->shouldReceive('resolve')->andReturn(['93.184.216.34']);
    }));
    Http::fake(['https://consumer.example/events' => Http::response('', 204)]);
    app(ClientWebhookSender::class)->send($delivery->id);

    expect($delivery->fresh()->status)->toBe('delivered');
    Http::assertSent(fn ($request): bool => $request->body() === $delivery->payload && $request->hasHeader('webhook-signature'));
});

test('configured managed connections keep acceptance rollback and callback outbox atomic', function (): void {
    config()->set('database.connections.managed-proof', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    config()->set('nvl-mail-notifications.connection', 'managed-proof');
    config()->set('nvl-media.connection', 'managed-proof');
    foreach (glob(dirname(__DIR__, 3).'/media/database/migrations/*.php') ?: [] as $path) {
        (require $path)->up();
    }
    (require __DIR__.'/../../database/migrations/2026_11_02_000000_nvl_mail_notifications_create_schema.php')->up();
    $client = managedProofClient();
    ClientWebhookEndpoint::factory()->create(['client_id' => $client->id, 'events' => ['message.accepted']]);
    $connection = DB::connection('managed-proof');
    $connection->beginTransaction();
    try {
        $message = app(MessageAcceptor::class)->accept($client, [
            'to' => [['email' => 'reader@example.com']], 'subject' => 'Rollback', 'text' => 'Body', 'send_at' => now()->addDay()->toIso8601String(),
            'references' => [['type' => 'invoice', 'id' => '42']],
            'attachments' => [['content_base64' => base64_encode('Rollback bytes'), 'name' => 'rollback.txt']],
        ], 'connection-rollback');
        expect($message->getConnectionName())->toBe('managed-proof');
        expect(Storage::disk('local')->allFiles())->toHaveCount(1);
        expect($message->messageAssets()->sole()->getConnectionName())->toBe('managed-proof');
        expect(EmailMessage::query()->count())->toBe(1);
        expect(ClientWebhookDelivery::query()->count())->toBe(1);
        expect(DB::connection()->table(ManagedConfiguration::table('email_messages'))->count())->toBe(0);
        Queue::assertNotPushed(DeliverClientWebhook::class);
    } finally {
        $connection->rollBack();
    }
    expect(EmailMessage::query()->count())->toBe(0);
    expect(ClientWebhookDelivery::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
    Queue::assertNothingPushed();
});

test('prefixed sender discovery casefolds Unicode on the package sqlite connection', function (): void {
    $client = managedProofClient();
    $sender = SenderIdentity::findOrFail($client->mailSettings->sender_identity_id);
    $sender->update(['name' => 'Straße Billing']);

    expect(SenderIdentity::query()->verified()->matchingSearch('STRASSE')->sole()->id)->toBe($sender->id);
});

test('mail settings reject non-string identity overrides before resolving sender routing', function (): void {
    $client = managedProofClient();

    expect(fn () => app(MailSettingsResolver::class)->resolve($client, ['sender_identity_id' => ['invalid']]))->toThrow(InvalidArgumentException::class);
});

test('managed schema identities describe every created table and preserve native constants', function (): void {
    $identity = SchemaIdentities::package('mail-notifications');
    expect($identity['tables'])->toHaveCount(29);
    expect(MailNotificationsTables::Notifications)->toBe('nvl_mail_notifications_notifications');
    expect(MailNotificationsTables::Events)->toBe('nvl_mail_notifications_events');
    expect(MailNotificationsTables::ScheduledMessages)->toBe('nvl_mail_notifications_scheduled_messages');
    foreach ($identity['tables'] as $key => $table) {
        if (in_array($key, ['notifications', 'events', 'scheduled_messages'], true)) {
            continue;
        }
        $name = MailNotificationsTables::get($key);
        expect(ManagedConfiguration::table($key))->toBe($name);
        expect(constant(MailNotificationsTables::class.'::'.$table['constant']))->toBe($table['default']);
        SchemaShape::assertCompatible(Schema::getConnection()->getSchemaBuilder(), $name, $table['columns'], $table['keys'], $table['foreign_keys']);
    }
});

test('managed storage follows canonical tables and inherits package and suite connections', function (): void {
    $suiteConnection = config('nvl-core.connection');
    $packageConnection = config('nvl-mail-notifications.connection');
    try {
        config()->set('nvl-core.connection', 'suite-connection');
        expect(ManagedConfiguration::connection())->toBe('suite-connection');
        config()->set('nvl-mail-notifications.connection', 'package-connection');
        config()->set('nvl-mail-notifications.tables.owners', 'canonical_mail_owners');
        expect(ManagedConfiguration::connection())->toBe('package-connection');
        expect((new Owner)->getConnectionName())->toBe('package-connection');
        expect(ManagedConfiguration::table('owners'))->toBe('canonical_mail_owners');
        expect((new Owner)->getTable())->toBe('canonical_mail_owners');
    } finally {
        config()->set('nvl-core.connection', $suiteConnection);
        config()->set('nvl-mail-notifications.connection', $packageConnection);
    }
});
