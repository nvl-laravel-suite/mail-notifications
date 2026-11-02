<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Models\SenderIdentity;
use Nvl\MailNotifications\Models\StagedMailFile;
use Nvl\MailNotifications\Services\Delivery\AssetManager;
use Nvl\MailNotifications\Services\Delivery\AttachmentRetention;
use Nvl\MailNotifications\Services\Delivery\MailMediaStorage;
use Nvl\MailNotifications\Services\Delivery\MessageAcceptor;
use Nvl\MailNotifications\Services\MailFilePruner;
use Nvl\MailNotifications\Services\MailHistoryPruner;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaUploader;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::fake('public');
});

function mediaStorageClient(): Client
{
    $account = ProviderAccount::factory()->create();
    $sender = SenderIdentity::factory()->for($account)->create(['email' => 'sender@example.com']);

    return Client::factory()->withMailSettings(['provider_account_id' => $account->id, 'sender_identity_id' => $sender->id, 'from_email' => $sender->email])->create(['owner_id' => $account->owner_id]);
}

function mediaStorageMessage(Client $client, string $key = 'media-proof'): EmailMessage
{
    return app(MessageAcceptor::class)->accept($client, ['to' => [['email' => 'reader@example.com']], 'subject' => 'Snapshot', 'text' => 'Body', 'attachments' => [['name' => 'invoice.txt', 'content_base64' => base64_encode('Immutable invoice')]]], $key);
}

test('catalog and accepted bytes use native media with independent immutable copies', function (): void {
    $client = mediaStorageClient();
    $catalog = app(AssetManager::class)->store($client, UploadedFile::fake()->createWithContent('invoice.txt', 'Original invoice'));

    $message = app(MessageAcceptor::class)->accept($client, ['to' => [['email' => 'reader@example.com']], 'subject' => 'Snapshot', 'text' => 'Body', 'attachments' => [$catalog->id]], 'catalog-snapshot');
    $snapshot = $message->messageAssets()->sole();
    app(MediaUploader::class)->replace($client, $catalog->media_id, UploadedFile::fake()->createWithContent('invoice.txt', 'Changed catalog'));
    app(AssetManager::class)->archive($catalog);

    expect($catalog->media_id)->not->toBeNull();
    expect($snapshot->media_id)->not->toBe($catalog->media_id);
    expect($snapshot->owns_file)->toBeTrue();
    expect(app(MailMediaStorage::class)->bytes($snapshot))->toBe('Original invoice');
    expect(Media::query()->count())->toBe(2);
    expect(StagedMailFile::query()->count())->toBe(0);
});

test('a provider payload rejection rolls back native media associations and stored objects', function (): void {
    $client = mediaStorageClient();
    config()->set('nvl-mail-notifications.managed.payload_limits.mailersend', 1);

    expect(fn () => mediaStorageMessage($client))->toThrow(ValidationException::class);

    expect(EmailMessage::query()->count())->toBe(0);
    expect(Media::query()->withTrashed()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    expect(StagedMailFile::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('native media authorization rejects a persisted client without leaving mail or media rows', function (): void {
    $client = mediaStorageClient();
    app()->bind(MediaAuthorization::class, fn (): MediaAuthorization => new class implements MediaAuthorization
    {
        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return false;
        }
    });

    expect(fn () => mediaStorageMessage($client))->toThrow(AuthorizationException::class);

    expect(EmailMessage::query()->count())->toBe(0);
    expect(Media::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('a divergent media connection is rejected before a catalog wrapper or file is created', function (string $configuration): void {
    $client = mediaStorageClient();
    config()->set('database.connections.other-media', config('database.connections.testing'));
    config()->set($configuration, 'other-media');

    expect(fn () => app(AssetManager::class)->store($client, UploadedFile::fake()->createWithContent('invoice.txt', 'Invoice')))->toThrow(RuntimeException::class, 'same actual database connection');

    expect(Asset::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'media root' => ['nvl-media.connection'],
    'owner-slot ledger' => ['nvl-media.connections.owner_slot_operations'],
]);

test('native retention preserves unknown outcomes and marks bytes deleted only after physical confirmation', function (): void {
    $this->freezeTime();
    $message = mediaStorageMessage(mediaStorageClient());
    $asset = $message->messageAssets()->sole();
    $message->update(['status' => 'failed', 'completed_at' => now()->subDays(5)]);
    $asset->update(['bytes_expires_at' => now()->subDay()]);
    $message->attempts()->update(['status' => 'unknown']);

    expect(app(AttachmentRetention::class)->prune())->toBe(0);
    expect($asset->fresh()->bytes_deleted_at)->toBeNull();
    Storage::disk($asset->disk)->assertExists($asset->path);

    $message->attempts()->update(['status' => 'failed']);
    expect(app(AttachmentRetention::class)->prune())->toBe(1);

    expect($asset->fresh()->bytes_deleted_at)->not->toBeNull();
    expect(Media::query()->withTrashed()->findOrFail($asset->media_id)->trashed())->toBeTrue();
    Storage::disk($asset->disk)->assertMissing($asset->path);
});

test('native retention defers physical purge through an outer rollback', function (): void {
    $message = mediaStorageMessage(mediaStorageClient());
    $asset = $message->messageAssets()->sole();
    $message->update(['status' => 'cancelled', 'cancelled_at' => now()->subDays(5)]);
    $message->attempts()->update(['status' => 'cancelled']);
    $asset->update(['bytes_expires_at' => now()->subDay()]);
    $connection = DB::connection(ManagedConfiguration::connection());
    $connection->beginTransaction();
    try {
        expect(app(AttachmentRetention::class)->prune())->toBe(0);
        Storage::disk($asset->disk)->assertExists($asset->path);
    } finally {
        $connection->rollBack();
    }

    expect($asset->fresh()->bytes_deleted_at)->toBeNull();
    expect(Media::query()->findOrFail($asset->media_id)->trashed())->toBeFalse();
    Storage::disk($asset->disk)->assertExists($asset->path);
});

test('legacy catalog bytes are ingested as an independent native message snapshot', function (): void {
    $client = mediaStorageClient();
    $catalog = Asset::factory()->create(['client_id' => $client->id, 'name' => 'legacy.txt', 'disk' => 'local', 'path' => 'assets/legacy.txt', 'mime_type' => 'text/plain', 'size' => 13, 'checksum' => hash('sha256', 'Legacy source')]);
    Storage::disk('local')->put($catalog->path, 'Legacy source');

    $message = app(MessageAcceptor::class)->accept($client, ['to' => [['email' => 'reader@example.com']], 'subject' => 'Legacy snapshot', 'text' => 'Body', 'attachments' => [$catalog->id]], 'legacy-copy');
    $snapshot = $message->messageAssets()->sole();
    Storage::disk('local')->delete($catalog->path);

    expect($catalog->fresh()->media_id)->toBeNull();
    expect($snapshot->media_id)->not->toBeNull();
    expect($snapshot->owns_file)->toBeTrue();
    expect(app(MailMediaStorage::class)->bytes($snapshot))->toBe('Legacy source');
});

test('native ingestion rejects unacceptable bytes without retaining a mail snapshot', function (string $name, string $bytes): void {
    $client = mediaStorageClient();

    expect(fn () => app(MessageAcceptor::class)->accept($client, ['to' => [['email' => 'reader@example.com']], 'subject' => 'Rejected upload', 'text' => 'Body', 'attachments' => [['name' => $name, 'content_base64' => base64_encode($bytes)]]], 'invalid-file'))->toThrow(ValidationException::class);

    expect(EmailMessage::query()->count())->toBe(0);
    expect(Media::query()->withTrashed()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'zero bytes' => ['empty.txt', ''],
    'extension mismatch' => ['invoice.pdf', 'Plain text masquerading as PDF'],
]);

test('failed native physical cleanup keeps the mail cleanup identity until a successful retry', function (): void {
    $message = mediaStorageMessage(mediaStorageClient());
    $asset = $message->messageAssets()->sole();
    $message->update(['status' => 'cancelled', 'cancelled_at' => now()->subDays(5)]);
    $message->attempts()->update(['status' => 'cancelled']);
    $asset->update(['bytes_expires_at' => now()->subDay()]);
    config()->set('nvl-media.delete_files_on_media_delete', false);
    $disk = Storage::disk('local');
    $driver = config('filesystems.disks.local.driver');
    Storage::forgetDisk('local');
    config()->set('filesystems.disks.local.driver', 'unavailable-test-driver');

    try {
        expect(app(AttachmentRetention::class)->prune())->toBe(0);
        expect($asset->fresh()->bytes_deleted_at)->toBeNull();
        expect($asset->fresh()->media_id)->toBe($asset->media_id);
        expect(Media::query()->withTrashed()->findOrFail($asset->media_id)->trashed())->toBeTrue();
        $disk->assertExists($asset->path);
    } finally {
        config()->set('filesystems.disks.local.driver', $driver);
        Storage::set('local', $disk);
    }

    expect(app(AttachmentRetention::class)->prune())->toBe(1);
    expect($asset->fresh()->bytes_deleted_at)->not->toBeNull();
    $disk->assertMissing($asset->path);
});

test('legacy catalog publication retains borrowed bytes and their cleanup journal until references are released', function (): void {
    $client = mediaStorageClient();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
    $catalog = Asset::factory()->create(['client_id' => $client->id, 'name' => 'legacy.png', 'disk' => 'local', 'path' => 'assets/legacy.png', 'mime_type' => 'image/png', 'size' => strlen($png), 'checksum' => hash('sha256', $png)]);
    Storage::disk('local')->put($catalog->path, $png);
    $message = EmailMessage::factory()->create(['client_id' => $client->id]);
    $borrowed = MessageAsset::factory()->create(['email_message_id' => $message->id, 'asset_id' => $catalog->id, 'disk' => $catalog->disk, 'path' => $catalog->path, 'name' => $catalog->name, 'mime_type' => $catalog->mime_type, 'size' => $catalog->size, 'checksum' => $catalog->checksum, 'owns_file' => false]);

    app(AssetManager::class)->publish($catalog);
    app(AssetManager::class)->archive($catalog);
    $journal = StagedMailFile::query()->where('disk', 'local')->where('path', 'assets/legacy.png')->sole();
    $journal->forceFill(['created_at' => now()->subDays(2)])->save();
    $pruner = app(MailFilePruner::class);

    expect($catalog->fresh()->media_id)->not->toBeNull();
    expect(app(MailMediaStorage::class)->bytes($borrowed))->toBe($png);
    expect($pruner->prune())->toBe(['files' => 0, 'records' => 0]);
    expect($journal->fresh())->not->toBeNull();
    Storage::disk('local')->assertExists('assets/legacy.png');

    $borrowed->delete();
    expect($pruner->prune())->toBe(['files' => 1, 'records' => 1]);
    Storage::disk('local')->assertMissing('assets/legacy.png');
});

test('native byte reads honor a denied download ability even when upload and association are allowed', function (): void {
    $client = mediaStorageClient();
    app()->bind(MediaAuthorization::class, fn (): MediaAuthorization => new class implements MediaAuthorization
    {
        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return $ability !== MediaAbility::Download;
        }
    });

    expect(fn () => mediaStorageMessage($client))->toThrow(AuthorizationException::class);

    expect(EmailMessage::query()->count())->toBe(0);
    expect(Media::query()->withTrashed()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('history pruning counts confirmed native catalog deletions', function (): void {
    $catalog = app(AssetManager::class)->store(mediaStorageClient(), UploadedFile::fake()->createWithContent('old.txt', 'Old bytes'));
    app(AssetManager::class)->archive($catalog);
    $catalog->update(['archived_at' => now()->subDays(400)]);
    $path = $catalog->path;

    $counts = app(MailHistoryPruner::class)->prune();

    expect($counts['assets'])->toBe(1);
    $this->assertModelMissing($catalog);
    Storage::disk('local')->assertMissing($path);
});

test('history pruning never counts native catalog deletion deferred to an outer transaction', function (bool $commit): void {
    $catalog = app(AssetManager::class)->store(mediaStorageClient(), UploadedFile::fake()->createWithContent('deferred.txt', 'Deferred bytes'));
    app(AssetManager::class)->archive($catalog);
    $catalog->update(['archived_at' => now()->subDays(400)]);
    $connection = $catalog->getConnection();
    $connection->beginTransaction();
    try {
        $counts = app(MailHistoryPruner::class)->prune();
        expect($counts['assets'])->toBe(0);
        Storage::disk('local')->assertExists($catalog->path);
        $this->assertModelExists($catalog);
    } finally {
        if ($commit) {
            $connection->commit();
        } else {
            $connection->rollBack();
        }
    }

    expect(Asset::query()->whereKey($catalog->id)->exists())->toBe(! $commit);
    expect(Storage::disk('local')->exists($catalog->path))->toBe(! $commit);
})->with(['outer commit' => true, 'outer rollback' => false]);
