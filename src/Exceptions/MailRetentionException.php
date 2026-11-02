<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

/**
 * Rejects a retained-history operation that cannot safely complete its configured policy.
 *
 * @api
 *
 * Reports invalid retention configuration or an unsafe pruning outcome.
 */
final class MailRetentionException extends MailNotificationsException {}
