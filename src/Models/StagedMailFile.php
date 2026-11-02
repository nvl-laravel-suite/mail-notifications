<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\MailNotifications\Database\Factories\StagedMailFileFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Recoverable file-staging journal bridging private storage writes and message acceptance.
 *
 * @property CarbonImmutable $created_at
 * @property string $id
 * @property string $client_id
 * @property string $disk
 * @property string $path
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read client_id
 * @nvl-consumer-read created_at
 */
#[Fillable(['client_id', 'disk', 'path', 'created_at'])]
class StagedMailFile extends Model
{
    public const string TABLE = MailNotificationsTables::StagedMailFiles;

    /** @use HasFactory<StagedMailFileFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    public const UPDATED_AT = null;

    /**
     * The consuming client owning this record.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function newFactory(): StagedMailFileFactory
    {
        return StagedMailFileFactory::new();
    }
}
