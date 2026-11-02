<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nvl\MailNotifications\Database\Seeders\IntegrationSeeder;
use Nvl\MailNotifications\Jobs\DeliverClientWebhook;
use Nvl\MailNotifications\Jobs\ProcessProviderSyncPage;
use Nvl\MailNotifications\Jobs\SendEmailMessage;
use Nvl\MailNotifications\Models\ClientWebhookDelivery;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\Integration;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\ProviderSyncRun;
use Nvl\MailNotifications\Models\StagedMailFile;
use Nvl\MailNotifications\Models\WebhookReceipt;

it('registers the opt-in delivery operations without scheduling or seeding them', function (): void {
    $commands = Artisan::all();
    foreach (['dispatch-pending', 'reconcile-webhooks', 'recover-client-webhooks', 'recover-provider-syncs', 'prune', 'prune-attachments', 'prune-orphan-files', 'quarantine-restored', 'integrations:sync'] as $suffix) {
        expect($commands)->toHaveKey('nvl:mail-notifications:delivery:'.$suffix);
    }
    expect($commands)->toHaveKey('nvl:mail-notifications:delivery:process-scheduled');
    expect($commands)->toHaveKey('nvl:mail-notifications:prune');
    expect(collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'nvl:mail-notifications:delivery:')))->toBeEmpty();
    expect(Integration::query()->count())->toBe(0);
});

it('rejects invalid bounded delivery operation limits without dispatching work', function (string $suffix, mixed $limit): void {
    Queue::fake();
    Http::preventStrayRequests();

    $this->artisan('nvl:mail-notifications:delivery:'.$suffix, ['--limit' => $limit])->expectsOutputToContain('Limit must be between 1 and 1000.')->assertFailed();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['dispatch-pending', 'reconcile-webhooks', 'recover-client-webhooks', 'recover-provider-syncs'])->with([0, 1001, 'many']);

it('dispatches bounded pending messages while leaving future work queued for its due time', function (): void {
    Queue::fake([SendEmailMessage::class]);
    $message = EmailMessage::factory()->create(['send_at' => now()->subMinute()]);
    $future = EmailMessage::factory()->create(['send_at' => now()->addDay()]);

    $this->artisan('nvl:mail-notifications:delivery:dispatch-pending', ['--limit' => 1])->expectsOutputToContain('Dispatched 1 messages.')->assertSuccessful();

    expect($message->fresh()->status)->toBe('queued');
    expect($future->fresh()->status)->toBe('accepted');
    Queue::assertPushed(SendEmailMessage::class, fn (SendEmailMessage $job): bool => $job->messageId === $message->id);
});

it('reconciles only verified receipts for the selected provider account', function (): void {
    Http::preventStrayRequests();
    $payload = ['type' => 'activity.unsupported', 'created_at' => now()->toIso8601String(), 'data' => ['id' => 'cli-event', 'message_id' => 'cli-message', 'recipient' => 'reader@example.test']];
    $receipt = WebhookReceipt::factory()->create(['payload' => $payload]);
    $other = WebhookReceipt::factory()->create(['payload' => $payload]);

    $this->artisan('nvl:mail-notifications:delivery:reconcile-webhooks', ['--limit' => 1, '--provider-account' => $receipt->provider_account_id])->expectsOutputToContain('Reconciled 1 webhook receipts.')->assertSuccessful();

    expect($receipt->fresh()->status)->toBe('ignored');
    expect($other->fresh()->processed_at)->toBeNull();
    Http::assertNothingSent();
});

it('recovers expired callback and provider inventory leases through their durable queues', function (): void {
    Queue::fake([DeliverClientWebhook::class, ProcessProviderSyncPage::class]);
    Http::preventStrayRequests();
    config()->set('queue.default', 'database');
    $delivery = ClientWebhookDelivery::factory()->create(['status' => 'processing', 'lease_until' => now()->subMinute()]);
    $run = ProviderSyncRun::factory()->create(['status' => 'running', 'lease_until' => now()->subMinute(), 'dispatched_at' => now()->subMinutes(2)]);

    $this->artisan('nvl:mail-notifications:delivery:recover-client-webhooks', ['--limit' => 1])->expectsOutputToContain('Queued 1 client webhook deliveries.')->assertSuccessful();
    $this->artisan('nvl:mail-notifications:delivery:recover-provider-syncs', ['--limit' => 1])->expectsOutputToContain('Dispatched 1 provider synchronization pages.')->assertSuccessful();

    expect($delivery->fresh()->status)->toBe('pending');
    expect($delivery->fresh()->lease_token)->toBeNull();
    expect($run->fresh()->lease_until)->toBeNull();
    Queue::assertPushed(DeliverClientWebhook::class, fn (DeliverClientWebhook $job): bool => $job->deliveryId === $delivery->id);
    Queue::assertPushed(ProcessProviderSyncPage::class, fn (ProcessProviderSyncPage $job): bool => $job->runId === $run->id);
    Http::assertNothingSent();
});

it('previews and prunes old terminal history without deleting uncertain messages', function (): void {
    $old = now()->subDays(400);
    $terminal = EmailMessage::factory()->create(['status' => 'cancelled', 'updated_at' => $old]);
    $unknown = EmailMessage::factory()->create(['status' => 'unknown', 'updated_at' => $old]);

    $this->artisan('nvl:mail-notifications:delivery:prune', ['--dry-run' => true])->expectsOutputToContain('Eligible: 1 messages')->assertSuccessful();
    $this->assertModelExists($terminal);
    $this->artisan('nvl:mail-notifications:delivery:prune')->expectsOutputToContain('Pruned: 1 messages')->assertSuccessful();

    $this->assertModelMissing($terminal);
    $this->assertModelExists($unknown);
    $this->artisan('nvl:mail-notifications:delivery:prune', ['--days' => 29])->expectsOutputToContain('Retention must be between 30 and 36500 days.')->assertFailed();
});

it('previews attachment and legacy orphan cleanup without deleting bytes or cleanup identities', function (): void {
    Storage::fake('local');
    $message = EmailMessage::factory()->create(['status' => 'cancelled']);
    $attachment = MessageAsset::factory()->for($message)->create(['owns_file' => true, 'bytes_expires_at' => now()->subDay()]);
    $orphan = StagedMailFile::factory()->create(['created_at' => now()->subDays(2)]);
    Storage::disk('local')->put($attachment->path, 'Retained attachment');
    Storage::disk('local')->put($orphan->path, 'Legacy orphan');

    $this->artisan('nvl:mail-notifications:delivery:prune-attachments', ['--dry-run' => true])->expectsOutputToContain('Eligible: 1 message attachments.')->assertSuccessful();
    $this->artisan('nvl:mail-notifications:delivery:prune-orphan-files', ['--dry-run' => true])->expectsOutputToContain('Would remove 1 orphan files and 1 staging records.')->assertSuccessful();

    expect($attachment->fresh()->bytes_deleted_at)->toBeNull();
    $this->assertModelExists($orphan);
    Storage::disk('local')->assertExists([$attachment->path, $orphan->path]);
});

it('requires paused delivery and an explicit valid cutoff before restored intents are quarantined', function (): void {
    $this->freezeTime();
    $message = EmailMessage::factory()->create(['created_at' => now()->subHour()]);
    $attempt = EmailAttempt::factory()->for($message)->create();
    $options = ['--before' => now()->toIso8601String(), '--limit' => 1];

    $this->artisan('nvl:mail-notifications:delivery:quarantine-restored', $options)->expectsOutputToContain('Pause outbound delivery')->assertFailed();
    config()->set('nvl-mail-notifications.managed.delivery.enabled', false);
    $this->artisan('nvl:mail-notifications:delivery:quarantine-restored', ['--before' => 'yesterday'])->expectsOutputToContain('absolute ISO8601')->assertFailed();
    $this->artisan('nvl:mail-notifications:delivery:quarantine-restored', [...$options, '--dry-run' => true])->expectsOutputToContain('Would hold 1 messages; 1 eligible remain unchanged.')->assertSuccessful();
    expect($message->fresh()->status)->toBe('accepted');
    $this->artisan('nvl:mail-notifications:delivery:quarantine-restored', $options)->expectsOutputToContain('Held 1 messages; 0 eligible remain.')->assertSuccessful();

    expect($message->fresh()->status)->toBe('unknown');
    expect($attempt->fresh()->status)->toBe('unknown');
    expect($attempt->fresh()->retryable)->toBeFalse();
});

it('queues inventory synchronization without contacting the provider', function (): void {
    Queue::fake([ProcessProviderSyncPage::class]);
    Http::preventStrayRequests();
    config()->set('queue.default', 'database');
    $account = ProviderAccount::factory()->create(['credentials' => ['api_key' => 'private-provider-token']]);

    $this->artisan('nvl:mail-notifications:delivery:integrations:sync', ['account' => $account->id])->expectsOutputToContain('Synchronization queued.')->expectsOutputToContain('Status: queued')->doesntExpectOutputToContain('private-provider-token')->assertSuccessful();

    $run = ProviderSyncRun::query()->sole();
    expect($run->provider_account_id)->toBe($account->id);
    Queue::assertPushed(ProcessProviderSyncPage::class, fn (ProcessProviderSyncPage $job): bool => $job->runId === $run->id);
    Http::assertNothingSent();
});

it('seeds its provider catalog idempotently without modifying an unrelated host integration table', function (): void {
    Http::preventStrayRequests();
    Schema::create('integrations', function (Blueprint $table): void {
        $table->string('name');
    });
    DB::table('integrations')->insert(['name' => 'Host integration']);
    $custom = Integration::factory()->create(['key' => 'smtp', 'name' => 'Operator SMTP']);

    $this->seed(IntegrationSeeder::class);
    $this->seed(IntegrationSeeder::class);

    expect(Integration::query()->count())->toBe(4);
    expect($custom->fresh()->name)->toBe('Operator SMTP');
    expect(DB::table('integrations')->sole()->name)->toBe('Host integration');
    Http::assertNothingSent();
});
