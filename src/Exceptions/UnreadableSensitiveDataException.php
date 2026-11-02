<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

/**
 * Prevents use of protected history that cannot be decoded with the configured storage policy.
 *
 * @api
 *
 * Reports protected history that cannot be restored without data loss.
 */
final class UnreadableSensitiveDataException extends SensitiveStorageException {}
