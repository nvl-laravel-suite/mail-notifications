<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Services\Delivery\MailMediaAuthorization;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\MediaRegistration;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaUploader;
use Nvl\Support\Enums\SystemActor;

function mailMediaPolicy(bool $grant = true): MailMediaAuthorization
{
    return new MailMediaAuthorization(new class($grant) implements MediaAuthorization
    {
        public function __construct(private bool $grant) {}

        public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
        {
            return $this->grant;
        }
    });
}

function mailPolicyMedia(Client $client): Media
{
    return Media::factory()->create(['disk' => 'local', 'is_public' => false, 'uploaded_by' => $client->id, 'uploaded_by_type' => $client->getMorphClass()]);
}

it('denies cross-client upload and association even when the fallback grants everything', function (): void {
    $client = Client::factory()->create();
    $foreign = Asset::factory()->create();
    $foreignClient = $foreign->client;
    $foreignMedia = mailPolicyMedia($foreignClient);
    $own = Asset::factory()->for($client)->create();
    $policy = mailMediaPolicy();

    expect($policy->allows($client, MediaAbility::Upload, owner: $foreign))->toBeFalse();
    expect($policy->allows($client, MediaAbility::Associate, $foreignMedia, $own))->toBeFalse();
    expect($policy->allows(SystemActor::Background, MediaAbility::Associate, $foreignMedia, $own))->toBeFalse();
});

it('preserves fallback denial for an otherwise eligible mail upload', function (): void {
    $owner = Asset::factory()->create();

    expect(mailMediaPolicy(false)->allows($owner->client, MediaAbility::Upload, owner: $owner))->toBeFalse();
    expect(mailMediaPolicy()->allows($owner->client, MediaAbility::Upload, owner: $owner))->toBeTrue();
});

it('never mutates accepted message bytes or attaches a replacement even after associations disappear', function (): void {
    $owner = MessageAsset::factory()->create();
    $client = $owner->emailMessage->client;
    $media = mailPolicyMedia($client);
    $replacement = mailPolicyMedia($client);
    $owner->update(['media_id' => $media->id]);
    $policy = mailMediaPolicy();

    expect($policy->allows($client, MediaAbility::Mutate, $media))->toBeFalse();
    expect($policy->allows(SystemActor::Background, MediaAbility::Mutate, $media))->toBeFalse();
    expect($policy->allows($client, MediaAbility::Associate, $replacement, $owner))->toBeFalse();
    expect($policy->allows($client, MediaAbility::Upload, owner: $owner))->toBeFalse();
});

it('permits initial same-client catalog copying into an unbound message owner', function (): void {
    $owner = MessageAsset::factory()->create();
    $client = $owner->emailMessage->client;
    $source = mailPolicyMedia($client);
    $owner->asset->update(['media_id' => $source->id]);
    $policy = mailMediaPolicy();

    expect($policy->allows($client, MediaAbility::View, $source, $owner))->toBeTrue();
    expect($policy->allows($client, MediaAbility::Associate, $source, $owner))->toBeTrue();
    expect($policy->allows($client, MediaAbility::Upload, owner: $owner))->toBeTrue();
});

it('permits expired terminal cleanup despite inactive clients but protects future or uncertain delivery', function (): void {
    $this->freezeTime();
    $owner = MessageAsset::factory()->create(['bytes_expires_at' => now()->subSecond()]);
    $message = $owner->emailMessage;
    $client = $message->client;
    $media = mailPolicyMedia($client);
    $owner->update(['media_id' => $media->id]);
    $message->update(['status' => 'submitted', 'next_attempt_at' => null]);
    $client->mailSettings->update(['status' => 'suspended']);
    $policy = mailMediaPolicy();

    expect($policy->allows($client, MediaAbility::Delete, $media, $owner))->toBeTrue();
    expect(mailMediaPolicy(false)->allows($client, MediaAbility::Delete, $media, $owner))->toBeFalse();
    expect($policy->allows($client, MediaAbility::Download, $media, $owner))->toBeFalse();
    $owner->update(['bytes_expires_at' => now()->addSecond()]);
    expect($policy->allows($client, MediaAbility::Delete, $media, $owner))->toBeFalse();
    $owner->update(['bytes_expires_at' => now()->subSecond()]);
    $attempt = EmailAttempt::factory()->for($message)->create(['status' => 'unknown']);
    expect($policy->allows($client, MediaAbility::Delete, $media, $owner))->toBeFalse();
    $attempt->update(['status' => 'sending']);
    expect($policy->allows($client, MediaAbility::Delete, $media, $owner))->toBeFalse();
    $attempt->update(['status' => 'failed']);
    $message->update(['next_attempt_at' => now()->addMinute()]);
    expect($policy->allows($client, MediaAbility::Delete, $media, $owner))->toBeFalse();
    $message->update(['status' => 'accepted', 'next_attempt_at' => null]);
    expect($policy->allows($client, MediaAbility::Delete, $media, $owner))->toBeFalse();
});

it('requires catalog archival and actual media linkage for deletion', function (): void {
    $owner = Asset::factory()->create();
    $client = $owner->client;
    $media = mailPolicyMedia($client);
    $other = mailPolicyMedia($client);
    $owner->update(['media_id' => $media->id]);
    $policy = mailMediaPolicy();

    expect($policy->allows($client, MediaAbility::Delete, $media, $owner))->toBeFalse();
    $owner->update(['archived_at' => now()]);
    expect($policy->allows($client, MediaAbility::Delete, $media, $owner))->toBeTrue();
    expect($policy->allows($client, MediaAbility::Delete, $other, $owner))->toBeFalse();
});

it('reloads current wrapper and client facts instead of carried ownership and status', function (): void {
    $owner = Asset::factory()->create();
    $client = $owner->client;
    $foreign = Client::factory()->create();
    $owner->client_id = $foreign->id;
    $client->mailSettings->update(['status' => 'suspended']);

    expect(mailMediaPolicy()->allows($foreign, MediaAbility::Upload, owner: $owner))->toBeFalse();
    expect(mailMediaPolicy()->allows($client, MediaAbility::Upload, owner: $owner))->toBeFalse();
});

it('delegates wholly non-mail resources to the existing host policy', function (): void {
    $media = Media::factory()->create(['uploaded_by' => null, 'uploaded_by_type' => null]);

    expect(mailMediaPolicy()->allows(SystemActor::Background, MediaAbility::Mutate, $media))->toBeTrue();
    expect(mailMediaPolicy(false)->allows(SystemActor::Background, MediaAbility::Mutate, $media))->toBeFalse();
});

it('blocks native cross-client uploads before file or media persistence', function (): void {
    Storage::fake('local');
    $owner = Asset::factory()->create(['name' => 'foreign.txt']);
    $foreignActor = Client::factory()->create();
    app()->instance(MediaAuthorization::class, mailMediaPolicy());
    app()->forgetScopedInstances();
    $slot = MediaRegistration::forOwner($owner)->getSlot('bytes');

    expect(fn () => app(MediaUploader::class)->upload($foreignActor, UploadedFile::fake()->createWithContent('foreign.txt', 'bytes'), 'local', $owner, $slot, 'foreign.txt'))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount((new Media)->getTable(), 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('blocks native cross-client associations despite a permissive host fallback', function (): void {
    $owner = Asset::factory()->create();
    $foreignActor = Client::factory()->create();
    $foreignMedia = mailPolicyMedia($foreignActor);
    app()->instance(MediaAuthorization::class, mailMediaPolicy());
    app()->forgetScopedInstances();

    expect(fn () => app(MediaAttachments::class)->attach($foreignActor, $foreignMedia, $owner, 'bytes', dispatchVariations: false))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount((new MediaAssociation)->getTable(), 0);
});

it('derives client scope from native registered associations before wrapper synchronization', function (): void {
    $owner = Asset::factory()->create();
    $actor = $owner->client;
    $media = mailPolicyMedia($actor);
    app(MediaAttachments::class)->attach($actor, $media, $owner, 'bytes', dispatchVariations: false);
    $foreign = Client::factory()->create();
    $policy = mailMediaPolicy();

    expect($owner->media_id)->toBeNull();
    expect($policy->allows($actor, MediaAbility::View, $media))->toBeTrue();
    expect($policy->allows($foreign, MediaAbility::View, $media))->toBeFalse();
});

it('denies new file work when the persisted owner is suspended', function (): void {
    $owner = Asset::factory()->create();
    $actor = $owner->client;
    $actor->owner->mailSettings->update(['status' => 'suspended']);

    expect(mailMediaPolicy()->allows($actor, MediaAbility::Upload, owner: $owner))->toBeFalse();
});

it('retains stored client uploader identities and rejects foreign access after namespace cleanup', function (): void {
    $client = Client::factory()->create();
    $foreign = Client::factory()->create();
    $previous = 'Nvl\\MailNotifications\\Managed\\Models\\Client';
    $media = Media::factory()->create(['disk' => 'local', 'is_public' => false, 'uploaded_by' => $client->id, 'uploaded_by_type' => $previous]);
    $policy = mailMediaPolicy();

    expect($client->getMorphClass())->toBe($previous)
        ->and($media->uploader)->toBeInstanceOf(Client::class)
        ->and($policy->allows($client, MediaAbility::Download, $media))->toBeTrue()
        ->and($policy->allows($foreign, MediaAbility::Download, $media))->toBeFalse();
});
