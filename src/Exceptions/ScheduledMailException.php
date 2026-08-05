<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

use RuntimeException;

/**
 * Reports invalid scheduling configuration or lifecycle operations.
 */
final class ScheduledMailException extends RuntimeException {}
