<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotifications\Exceptions\ManagedDeliveryConflict;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Models\StagedMailFile;
use Nvl\MailNotifications\Services\Delivery\AssetManager;
use Nvl\MailNotifications\Services\Delivery\AttachmentRetention;
use Nvl\MailNotifications\Services\Delivery\MailMediaStorage;
use Nvl\MailNotifications\Support\MailMediaSlots;
use Nvl\MailNotifications\Support\ManagedConfiguration;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Support\OwnerRegistry;

it('declares bounded exclusive original-byte slots without automatic image conversion', function (): void {
    foreach ([new Asset(['is_public' => false]), new MessageAsset] as $owner) {
        $registration = MediaRegistration::forOwner($owner);
        $slot = $registration->getSlot('bytes');
        expect($registration->slots())->toHaveCount(1);
        expect($slot)->not->toBeNull();
        expect($slot->isSingleFile)->toBeTrue();
        expect($slot->slotSizeLimit)->toBe(1);
        expect($slot->sharingMode)->toBe(MediaSlot::SHARING_EXCLUSIVE);
        expect($slot->maxFileSize)->toBe(10 * 1024 * 1024);
        expect($slot->acceptedMimeTypes)->toBe(MailMediaSlots::MIME_TYPES);
        expect($slot->isPublic)->toBeFalse();
        expect($slot->resolveDisk())->toBe('local');
        expect($slot->shouldConvertOriginal())->toBeFalse();
        expect($slot->getConversionDefinitions())->toBe([]);
        expect($registration->conversions())->toBe([]);
    }
    expect(new StagedMailFile)->not->toBeInstanceOf(HasNvlMedia::class);
});

it('chooses public catalog storage explicitly while keeping accepted attachments private', function (): void {
    config()->set('nvl-mail-notifications.managed.storage.disk', 'mail-private');
    config()->set('nvl-mail-notifications.managed.storage.public_disk', 'mail-public');
    $catalog = MediaRegistration::forOwner(new Asset(['is_public' => true]))->getSlot('bytes');
    $attachment = MediaRegistration::forOwner(new MessageAsset)->getSlot('bytes');

    expect($catalog->isPublic)->toBeTrue();
    expect($catalog->resolveDisk())->toBe('mail-public');
    expect($catalog->isExclusive())->toBeTrue();
    expect($attachment->isPrivate())->toBeTrue();
    expect($attachment->resolveDisk())->toBe('mail-private');
});

it('admits exactly the two persisted media owner classes through Core capabilities', function (): void {
    $owners = app(OwnerRegistry::class);

    expect($owners->model(Asset::class))->toBe(Asset::class);
    expect($owners->model(MessageAsset::class))->toBe(MessageAsset::class);
    expect(fn () => $owners->model(StagedMailFile::class))->toThrow(InvalidArgumentException::class);
});

it('creates indexed nullable Media associations with durable snapshot metadata from the start', function (): void {
    $catalog = Asset::factory()->create(['name' => 'snapshot.pdf', 'path' => 'pending/path.pdf', 'checksum' => hash('sha256', 'snapshot')]);
    $attachment = MessageAsset::factory()->create(['asset_id' => $catalog->id]);

    expect($catalog->fresh()->only(['name', 'path', 'checksum', 'media_id']))->toBe(['name' => 'snapshot.pdf', 'path' => 'pending/path.pdf', 'checksum' => hash('sha256', 'snapshot'), 'media_id' => null]);
    expect($attachment->fresh()->media_id)->toBeNull();
    foreach (['assets', 'message_assets'] as $logical) {
        $schema = Schema::connection(ManagedConfiguration::connection());
        $indexes = $schema->getIndexes(ManagedConfiguration::table($logical));
        expect(array_any($indexes, fn (array $index): bool => $index['columns'] === ['media_id']))->toBeTrue();
        expect(array_any($schema->getForeignKeys(ManagedConfiguration::table($logical)), fn (array $foreign): bool => in_array('media_id', $foreign['columns'], true)))->toBeFalse();
    }
});

it('checks accepted native media availability through the mail byte adapter', function (bool $exists): void {
    $attachment = new MessageAsset(['media_id' => fake()->uuid(), 'disk' => 'local', 'path' => 'historical-path']);
    $storage = Mockery::mock(MailMediaStorage::class);
    $storage->shouldReceive('exists')->once()->with($attachment)->andReturn($exists);
    app()->instance(MailMediaStorage::class, $storage);

    expect($attachment->downloadStatus())->toBe($exists ? 'available' : 'unavailable');
})->with([true, false]);

it('reports unavailable retry bytes as a domain conflict and preserves their retention fence', function (): void {
    $message = EmailMessage::factory()->create(['status' => 'failed']);
    $attachment = MessageAsset::factory()->create(['email_message_id' => $message->id, 'bytes_expires_at' => now()->subMinute()]);
    $expiresAt = $attachment->bytes_expires_at;

    expect(fn () => app(AttachmentRetention::class)->prepareRetry($message))->toThrow(ManagedDeliveryConflict::class, 'Attachment bytes are expired or unavailable. Submit a new message with the files.');
    expect($attachment->fresh()->bytes_expires_at->equalTo($expiresAt))->toBeTrue();
});

it('reports archived catalog assets with their model identity before publication', function (): void {
    $asset = Asset::factory()->create(['archived_at' => now(), 'mime_type' => 'image/png']);

    try {
        app(AssetManager::class)->publish($asset);
        $this->fail('Archived catalog assets cannot be published.');
    } catch (ModelNotFoundException $exception) {
        expect($exception->getModel())->toBe(Asset::class);
        expect($exception->getIds())->toBe([$asset->id]);
    }
    expect($asset->fresh()->is_public)->toBeFalse();
});

it('validates the persisted public MIME type even when held metadata is stale', function (bool $stale): void {
    $asset = Asset::factory()->create(['mime_type' => 'application/pdf']);
    if ($stale) {
        $asset->mime_type = 'image/png';
    }

    try {
        app(AssetManager::class)->publish($asset);
        $this->fail('Only supported raster images can be published.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['asset' => ['Only raster images can be published.']]);
    }
    expect($asset->fresh()->is_public)->toBeFalse();
})->with([false, true]);

it('rechecks locked catalog state before publishing a stale held public asset', function (): void {
    $held = Asset::factory()->create(['mime_type' => 'image/png', 'is_public' => true]);
    $assetId = $held->id;
    $current = $held->fresh();
    $storage = Mockery::mock(MailMediaStorage::class);
    $storage->shouldReceive('assertConnection')->once()->with($held);
    $storage->shouldNotReceive('relocate');
    $assets = new AssetManager($storage);
    $assets->archive($current);

    expect($held->is_public)->toBeTrue();
    expect(fn () => $assets->publish($held))->toThrow(ModelNotFoundException::class);
    expect(Asset::findOrFail($assetId)->archived_at)->not->toBeNull();
    expect(Asset::findOrFail($assetId)->is_public)->toBeFalse();
});
