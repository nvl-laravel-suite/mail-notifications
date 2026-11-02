<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

/**
 * Signals an invalid or failed protected-field storage operation.
 *
 * @api
 *
 * Reports invalid or failed sensitive-storage configuration and writes.
 */
class SensitiveStorageException extends MailNotificationsException {}
