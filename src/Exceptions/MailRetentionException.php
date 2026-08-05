<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Exceptions;

use RuntimeException;

/**
 * Reports invalid retention configuration or an unsafe pruning outcome.
 */
final class MailRetentionException extends RuntimeException {}
