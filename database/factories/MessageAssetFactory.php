<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\MessageAsset;

/**
 * Creates isolated Message Asset fixtures on the configured mail storage.
 *
 * @extends Factory<MessageAsset>
 */
class MessageAssetFactory extends Factory
{
    protected $model = MessageAsset::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<MessageAsset>, mixed>
     */
    public function definition(): array
    {
        return [
            'media_id' => null,
            'email_message_id' => EmailMessage::factory(),
            'asset_id' => fn (array $attributes): string => Asset::factory()->create(['client_id' => EmailMessage::query()->whereKey($attributes['email_message_id'])->firstOrFail()->client_id])->id,
            'disk' => fn (array $attributes): string => Asset::query()->whereKey($attributes['asset_id'])->firstOrFail()->disk,
            'path' => fn (array $attributes): string => Asset::query()->whereKey($attributes['asset_id'])->firstOrFail()->path,
            'name' => fn (array $attributes): string => Asset::query()->whereKey($attributes['asset_id'])->firstOrFail()->name,
            'mime_type' => fn (array $attributes): string => Asset::query()->whereKey($attributes['asset_id'])->firstOrFail()->mime_type,
            'size' => fn (array $attributes): int => Asset::query()->whereKey($attributes['asset_id'])->firstOrFail()->size,
            'checksum' => fn (array $attributes): string => Asset::query()->whereKey($attributes['asset_id'])->firstOrFail()->checksum,
            'disposition' => 'attachment',
        ];
    }
}
