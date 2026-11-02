<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\MailNotifications\Models\Asset;
use Nvl\MailNotifications\Models\Client;

/**
 * Creates isolated Asset fixtures on the configured mail storage.
 *
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * Define isolated persisted attributes without initiating delivery or external provider calls.
     *
     * @return array<model-property<Asset>, mixed>
     */
    public function definition(): array
    {
        return ['media_id' => null, 'client_id' => Client::factory(), 'name' => 'document.pdf', 'disk' => 'local', 'path' => 'assets/'.fake()->uuid().'.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'checksum' => hash('sha256', 'test'), 'is_public' => false, 'archived_at' => null];
    }
}
