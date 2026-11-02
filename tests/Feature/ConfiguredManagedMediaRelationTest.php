<?php

declare(strict_types=1);

use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\MessageAsset;
use Nvl\MailNotifications\Tests\Fixtures\ConfiguredManagedMedia;

it('loads the selected native Media root through both managed byte-owner relations', function (): void {
    $media = ConfiguredManagedMedia::factory()->create();
    $catalog = Asset::factory()->create(['media_id' => $media->id]);
    $attachment = MessageAsset::factory()->create(['asset_id' => $catalog->id, 'media_id' => $media->id]);

    expect($catalog->fresh()->media)->toBeInstanceOf(ConfiguredManagedMedia::class);
    expect($attachment->fresh()->media)->toBeInstanceOf(ConfiguredManagedMedia::class);
});
