<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

use RuntimeException;

/**
 * Reports invalid or failed sensitive-storage configuration and writes.
 */
class SensitiveStorageException extends RuntimeException {}
