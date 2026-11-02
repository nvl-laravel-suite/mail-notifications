<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Nvl\MailNotifications\Database\Factories\IntegrationFactory;
use Nvl\MailNotifications\Definitions\Tables\MailNotificationsTables;
use Nvl\MailNotifications\Support\UsesManagedStorage;

/** Managed mail persistence model.
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string $icon
 * @property array<array-key, mixed> $capabilities
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read key
 * @nvl-consumer-read name
 * @nvl-consumer-read icon
 * @nvl-consumer-read capabilities
 * @nvl-consumer-read created_at
 * @nvl-consumer-read updated_at
 */
#[Fillable(['key', 'name', 'icon', 'capabilities'])]
class Integration extends Model
{
    public const string TABLE = MailNotificationsTables::Integrations;

    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    use HasUuids;
    use UsesManagedStorage;

    public const array SUPPORTED_KEYS = ['mailersend', 'sendgrid', 'mailgun', 'smtp'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['capabilities' => 'array'];
    }

    /**
     * Provider accounts belonging to this owner or integration.
     *
     * @return HasMany<ProviderAccount, $this>
     */
    public function providerAccounts(): HasMany
    {
        return $this->hasMany(ProviderAccount::class);
    }

    protected static function newFactory(): IntegrationFactory
    {
        return IntegrationFactory::new();
    }
}
