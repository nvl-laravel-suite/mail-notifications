<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Services\Integrations;

use Nvl\MailNotifications\Models\EmailMessage;

/** Reads provider metadata only after the host authorizes the persisted local message.
 * @internal
 */
class InspectProviderMessage
{
    public function __construct(private ProviderApiManager $manager) {}

    /**
     * Inspect provider diagnostics only for identifiers belonging to a locally authorized message attempt.
     *
     * @return list<array{attempt_id: string, result: ProviderApiResult}>
     */
    public function handle(EmailMessage $message): array
    {
        $message->loadMissing('client');
        $attempt = $message->attempts()->where('provider_account_id', $message->provider_account_id)->with('providerAccount')->latest('number')->first();
        if ($attempt === null) {
            return [];
        }
        $account = $attempt->providerAccount;
        if ($account === null || $message->client === null || $account->owner_id !== $message->client->owner_id) {
            return [];
        }
        $ids = $account->provider === 'sendgrid' ? ($attempt->remote_message_ids ?? []) : array_filter([$attempt->provider_message_id]);
        if ($ids === []) {
            return [['attempt_id' => $attempt->id, 'result' => new ProviderApiResult('pending', message: 'A provider email identifier has not been recorded yet.')]];
        }
        $results = [];
        foreach (array_slice(array_values(array_unique($ids)), 0, 3) as $id) {
            $result = $this->manager->for($account)->message($account, $id);
            if (count($ids) > 3) {
                $result = new ProviderApiResult($result->status, $result->data, false, 'Inspection is limited to the first three recipient identifiers in the latest attempt. '.($result->message ?? ''));
            }
            $results[] = ['attempt_id' => $attempt->id, 'result' => $result];
        }

        return $results;
    }
}
