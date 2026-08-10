<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Nvl\MailNotifications\Contracts\MailNotificationReadAuthorization;
use Nvl\MailNotifications\Enums\MailDeliveryStatus;
use Nvl\MailNotifications\Enums\MailNotificationReadAbility;
use Nvl\MailNotifications\Models\MailNotification;
use Nvl\MailNotifications\ValueObjects\MailNotificationSuggestion;

/**
 * Returns minimal, bounded autocomplete results for authorized administrators.
 */
final readonly class SuggestMailNotificationsAction
{
    public function __construct(private MailNotificationReadAuthorization $authorization) {}

    /**
     * @return list<MailNotificationSuggestion>
     */
    public function execute(
        Authenticatable $actor,
        string $search,
        ?MailDeliveryStatus $status = null,
        ?string $mailer = null,
        ?int $limit = null,
    ): array {
        $this->authorization->authorize(MailNotificationReadAbility::Suggest, $actor);
        $search = trim($search);

        if ($search === '' || mb_strlen($search) > 160) {
            throw new InvalidArgumentException(
                'Mail notification suggestions require between 1 and 160 search characters.',
            );
        }

        if ($mailer !== null && mb_strlen(trim($mailer)) > 128) {
            throw new InvalidArgumentException(
                'The mail notification mailer must not exceed 128 characters.',
            );
        }

        $maximum = config('mail-notifications.management.suggestion_limit', 20);

        if (! is_int($maximum) || $maximum < 1) {
            $maximum = 20;
        }

        $term = "%{$search}%";

        $query = MailNotification::query()
            ->select([
                'id',
                'subject',
                'primary_recipient_email',
                'status',
                'created_at',
            ])
            ->where(static function (Builder $query) use ($term): void {
                $query->where('subject', 'like', $term)
                    ->orWhere('primary_recipient_email', 'like', $term)
                    ->orWhere('provider_message_id', 'like', $term);
            });

        if ($status instanceof MailDeliveryStatus) {
            $query->where('status', $status->value);
        }

        if ($mailer !== null && trim($mailer) !== '') {
            $query->where('mailer', trim($mailer));
        }

        return array_values($query
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit ?? $maximum, $maximum)))
            ->get()
            ->map(static fn (MailNotification $notification): MailNotificationSuggestion => MailNotificationSuggestion::fromModel($notification))
            ->values()
            ->all());
    }
}
