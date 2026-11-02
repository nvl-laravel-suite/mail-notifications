<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

/**
 * Rejects an invalid scheduled-mail operation or an unsafe lifecycle transition.
 *
 * @api
 *
 * Reports invalid scheduling configuration or lifecycle operations.
 */
final class ScheduledMailException extends MailNotificationsException {}
