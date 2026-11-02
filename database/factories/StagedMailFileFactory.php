<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\StagedMailFile;

/**
 * Creates isolated Staged Mail File fixtures on the configured mail storage.
 *
 * @extends Factory<StagedMailFile>
 */
class StagedMailFileFactory extends Factory
{
    protected $model = StagedMailFile::class;

    /**
     * Define the model's default state.
     *
     * @return array<model-property<StagedMailFile>, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'disk' => 'local',
            'path' => fn (array $attributes): string => 'message-attachments/'.Client::query()->whereKey($attributes['client_id'])->firstOrFail()->id.'/'.Str::uuid(),
        ];
    }
}
