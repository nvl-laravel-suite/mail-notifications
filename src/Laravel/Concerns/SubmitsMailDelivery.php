<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Laravel\Concerns;

use Closure;
use Illuminate\Mail\Mailable;
use Nvl\MailNotifications\Models\Client;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Services\Delivery\EmailContent;
use Nvl\MailNotifications\Services\MailablePreparation;
use Nvl\MailNotifications\Services\ManagedDelivery;

/**
 * Prepares ordinary host mailables for the opt-in managed delivery engine.
 *
 * @phpstan-import-type ManagedMessageInput from EmailContent
 *
 * @mixin Mailable
 *
 * @api
 */
trait SubmitsMailDelivery
{
    /**
     * Render a clone once through an isolated, event-free, in-memory mailer.
     *
     * @param  array<string, mixed>  $options  Managed message attributes except content, envelope and attachments.
     * @return ManagedMessageInput Validated prepared input; sender settings remain authoritative.
     */
    public function toMailDelivery(array $options = []): array
    {
        return app(MailablePreparation::class)->prepare($this, $options);
    }

    /**
     * Prepare and accept through the same native boundary used by HTTP callers.
     *
     * @param  array<string, mixed>  $options  See toMailDelivery().
     * @param  null|Closure(Client): (void|bool)  $authorize  Explicit live host authorizer.
     */
    public function submitMailDelivery(Client $client, string $idempotencyKey, array $options = [], ?Closure $authorize = null): EmailMessage
    {
        return app(ManagedDelivery::class)->send($client, $this->toMailDelivery($options), $idempotencyKey, $authorize);
    }
}
