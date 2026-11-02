<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Delivery;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Support\Enums\SystemActor;

/**
 * Opt-in host policy decorator retaining client scope and immutable mail bytes.
 *
 * The host explicitly extends its existing MediaAuthorization binding with this
 * decorator. It never grants an ability that the wrapped policy denies.
 *
 * @api
 */
final readonly class MailMediaAuthorization implements MediaAuthorization
{
    private const array FINAL_STATUSES = ['submitted', 'delivered', 'partially_delivered', 'failed', 'blocked', 'cancelled'];

    public function __construct(private MediaAuthorization $fallback) {}

    /** Apply fresh mail scope and lifecycle constraints before the wrapped host policy. */
    public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
    {
        if ($actor instanceof Client) {
            $actor = $this->currentClient($actor);
            if ($actor === null) {
                return false;
            }
        }
        $mailOwner = $owner instanceof Asset || $owner instanceof MessageAsset;
        $currentOwner = $mailOwner ? $this->currentOwner($owner) : null;
        if ($mailOwner && $currentOwner === null) {
            return false;
        }
        $currentMedia = $media === null ? null : MediaModelRegistry::query()->withTrashed()->useWritePdo()->whereKey($media->id)->first();
        if ($media !== null && $currentMedia === null) {
            return false;
        }
        $linked = $currentMedia === null ? [] : $this->linkedOwners($currentMedia);
        if ($linked === null) {
            return false;
        }
        $uploader = $currentMedia !== null && $currentMedia->uploaded_by_type === (new Client)->getMorphClass()
            ? Client::query()->useWritePdo()->whereKey($currentMedia->uploaded_by)->first()
            : null;
        $mailUpload = $currentMedia !== null && $currentMedia->uploaded_by_type === (new Client)->getMorphClass();
        if (! $mailOwner && $linked === [] && ! $mailUpload) {
            return $this->fallback->allows($actor, $ability, $currentMedia, $owner);
        }
        if (($owner !== null && ! $mailOwner) || ($mailUpload && $uploader === null)) {
            return false;
        }

        $owners = $linked;
        if ($currentOwner !== null) {
            $owners[$this->ownerKey($currentOwner)] = $currentOwner;
        }
        $clients = [];
        foreach ($owners as $target) {
            $client = $target instanceof Asset
                ? $target->client()->useWritePdo()->first()
                : $target->emailMessage()->useWritePdo()->first()?->client()->useWritePdo()->first();
            if (! $client instanceof Client) {
                return false;
            }
            $clients[$client->id] = $client;
            if (($actor instanceof Client && $actor->id !== $client->id) || ($uploader !== null && $uploader->id !== $client->id)) {
                return false;
            }
            if ($ability === MediaAbility::Mutate && $target instanceof MessageAsset) {
                return false;
            }
        }
        if ($uploader !== null) {
            $clients[$uploader->id] = $uploader;
            if ($actor instanceof Client && $actor->id !== $uploader->id) {
                return false;
            }
        }
        if ($currentOwner instanceof MessageAsset && $currentOwner->media_id !== null
            && ($ability === MediaAbility::Upload || ($ability === MediaAbility::Associate && $currentMedia?->id !== $currentOwner->media_id))) {
            return false;
        }

        if ($ability === MediaAbility::Delete) {
            if ($currentMedia === null || $owners === []) {
                return false;
            }
            foreach ($owners as $target) {
                if ($target->media_id !== $currentMedia->id || ! $this->deletable($target)) {
                    return false;
                }
            }
        } else {
            foreach ($clients as $client) {
                if (! $client->isActive() || ! $client->owner->isActive()) {
                    return false;
                }
            }
        }

        return $this->fallback->allows($actor, $ability, $currentMedia, $currentOwner);
    }

    private function currentClient(Client $client): ?Client
    {
        if (! $this->canonicalIdentity($client, new Client)) {
            return null;
        }

        return Client::query()->useWritePdo()->whereKey($client->getKey())->first();
    }

    private function currentOwner(Asset|MessageAsset $owner): Asset|MessageAsset|null
    {
        $prototype = $owner instanceof Asset ? new Asset : new MessageAsset;
        if (! $this->canonicalIdentity($owner, $prototype)) {
            return null;
        }

        return $prototype->newQuery()->useWritePdo()->whereKey($owner->getKey())->first();
    }

    private function canonicalIdentity(Model $model, Model $prototype): bool
    {
        return $model->exists && is_string($model->getKey()) && $model->getKey() !== ''
            && $model->getRawOriginal($prototype->getKeyName()) === $model->getKey()
            && $model->getTable() === $prototype->getTable()
            && $model->getKeyName() === $prototype->getKeyName()
            && $model->getConnection() === $prototype->getConnection();
    }

    /** @return array<string, Asset|MessageAsset>|null */
    private function linkedOwners(Media $media): ?array
    {
        $owners = [];
        foreach ([Asset::class, MessageAsset::class] as $class) {
            foreach ($class::query()->useWritePdo()->where('media_id', $media->id)->get() as $owner) {
                $owners[$this->ownerKey($owner)] = $owner;
            }
            $type = (new $class)->getMorphClass();
            foreach ($media->associations()->useWritePdo()->where('associable_type', $type)->get() as $association) {
                $owner = $class::query()->useWritePdo()->whereKey($association->associable_id)->first();
                if ($owner === null) {
                    return null;
                }
                $owners[$this->ownerKey($owner)] = $owner;
            }
        }

        return $owners;
    }

    private function ownerKey(Asset|MessageAsset $owner): string
    {
        return $owner::class.':'.$owner->id;
    }

    private function deletable(Asset|MessageAsset $owner): bool
    {
        if ($owner instanceof Asset) {
            return $owner->archived_at !== null;
        }
        if ($owner->bytes_expires_at === null || $owner->bytes_expires_at->isFuture()) {
            return false;
        }
        $message = $owner->emailMessage()->useWritePdo()->first();

        return $message !== null && in_array($message->status, self::FINAL_STATUSES, true)
            && $message->next_attempt_at === null
            && ! $message->attempts()->whereIn('status', ['sending', 'unknown'])->exists();
    }
}
