<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

/**
 * Reports protected history that cannot be restored without data loss.
 */
final class UnreadableSensitiveDataException extends SensitiveStorageException {}
