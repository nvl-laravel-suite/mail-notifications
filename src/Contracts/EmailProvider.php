<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Contracts;

use Nvl\MailNotifications\Models\EmailAttempt;
use Nvl\MailNotifications\Models\EmailMessage;
use Nvl\MailNotifications\Services\Delivery\ProviderResult;

/**
 * Validates and submits one persisted delivery attempt to its configured provider.
 */
interface EmailProvider
{
    /**
     * Reject unsupported or oversized persisted content before this attempt reaches provider transport.
     */
    public function validatePayload(EmailMessage $message, EmailAttempt $attempt): void;

    /**
     * Submit the persisted envelope and bytes for this attempt; distinguish acceptance, rejection and uncertainty.
     */
    public function send(EmailMessage $message, EmailAttempt $attempt): ProviderResult;
}
