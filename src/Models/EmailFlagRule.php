<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\EmailFlagRuleFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Enums\EmailFlagType;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/**
 * Client-owned match rule for recipient addresses, domains or subject text.
 *
 * @property EmailFlagType $type
 * @property string $id
 * @property string $client_id
 * @property string $label
 * @property string $color
 * @property string $value
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read client_id
 * @nvl-consumer-read label
 * @nvl-consumer-read color
 * @nvl-consumer-read type
 * @nvl-consumer-read value
 * @nvl-consumer-read enabled
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['client_id', 'label', 'color', 'type', 'value', 'enabled'])]
class EmailFlagRule extends Model
{
    public const string TABLE = MailNotificationsTables::EmailFlagRules;

    /** @use HasFactory<EmailFlagRuleFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    protected $attributes = ['enabled' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => EmailFlagType::class, 'enabled' => 'boolean'];
    }

    /**
     * The consuming client owning this record.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Saved message tags that retain this rule's match evidence.
     *
     * @return HasMany<EmailMessageFlag, $this>
     */
    public function messageFlags(): HasMany
    {
        return $this->hasMany(EmailMessageFlag::class);
    }

    protected static function newFactory(): EmailFlagRuleFactory
    {
        return EmailFlagRuleFactory::new();
    }
}
