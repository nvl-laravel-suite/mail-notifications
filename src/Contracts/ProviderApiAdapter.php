<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Nvl\MailNotifications\Models\ProviderAccount;
use Nvl\MailNotifications\Services\Integrations\ProviderApiResult;

/**
 * Reads bounded provider administration pages and safe message diagnostics for an already authorized account.
 */
interface ProviderApiAdapter
{
    /**
     * Report supported send, domain, sender and message-inspection capabilities without network calls.
     *
     * @return array{send: bool, domains: bool, senders: bool, message: bool, messages: bool}
     */
    public function capabilities(): array;

    /** Result data is one page of {remote_id, domain, status, dns_records}; pass nextCursor to continue. */
    public function domains(ProviderAccount $account, ?string $cursor = null): ProviderApiResult;

    /**
     * Read verification/DNS details for a provider-domain identifier already authorized by the caller.
     */
    public function domainDns(ProviderAccount $account, string $remoteId): ProviderApiResult;

    /** Result data is one page of {remote_id, email, name, status, domain_remote_id, reply_to_email, reply_to_name}. */
    public function senders(ProviderAccount $account, ?string $cursor = null): ProviderApiResult;

    /** Only call with an identifier from a locally authorized message attempt. Bodies are never returned. */
    public function message(ProviderAccount $account, string $remoteId): ProviderApiResult;

    /**
     * Read a bounded diagnostics page using the provider-supported filters; never return message bodies.
     *
     * @param  array{domain_id?: string, domain?: string, date_from?: int, date_to?: int, message_id?: string}  $filters
     */
    public function messages(ProviderAccount $account, array $filters = []): ProviderApiResult;
}
