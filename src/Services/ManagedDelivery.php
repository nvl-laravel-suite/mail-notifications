<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Services\Delivery\MessageAcceptor;
use Nvl\MailNotifications\Support\ManagedConfiguration;

/**
 * Complete public boundary for validated, authorized managed delivery.
 *
 * @api
 */
class ManagedDelivery
{
    public function __construct(private MessageInputValidator $validator, private MessageAcceptor $acceptor) {}

    /**
     * Accept rendered content for one currently eligible managed client.
     *
     * The explicit authorizer must return false or throw on denial; without one, the host's
     * nvl-send-managed-mail Gate must authorize an authenticated principal.
     *
     * @param  array<string, mixed>  $input  Shared MessageInputValidator schema.
     * @param  null|Closure(Client): (void|bool)  $authorize  A live host authorization check.
     */
    public function send(Client $client, array $input, string $idempotencyKey, ?Closure $authorize = null): EmailMessage
    {
        if (! ManagedConfiguration::enabled()) {
            throw new AuthorizationException('Managed mail delivery is disabled.');
        }

        $validated = $this->validator->validate($input, $idempotencyKey);
        $check = function (Client $current) use ($authorize): void {
            if ($authorize === null) {
                $actor = Auth::user();
                if ($actor instanceof Model) {
                    $actor = $actor->newQuery()->find($actor->getKey());
                }
                if ($actor === null) {
                    throw new AuthorizationException('An authenticated actor or explicit authorizer is required.');
                }
                Gate::forUser($actor)->authorize('nvl-send-managed-mail', $current);
            } elseif ($authorize($current) === false) {
                throw new AuthorizationException('Managed mail delivery was not authorized.');
            }

            $current->unsetRelations();
            $settings = $current->effectiveMailSettings();
            $account = ProviderAccount::query()->where('owner_id', $current->owner_id)->find($settings['provider_account_id']);

            if (! $current->isActive() || ! $current->owner->isActive() || ! $account?->isActive()) {
                throw new AuthorizationException('The client, owner and provider must be active.');
            }
        };
        $current = Client::query()->whereKey($client->getKey())->firstOrFail();
        $check($current);

        return $this->acceptor->accept($current, $validated, $idempotencyKey, $check);
    }
}
