<?php

declare(strict_types=1);

namespace Nvl\MailNotifications\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Stores model timestamps as UTC wall-clock values and reads stored values back as UTC instants,
 * independently of the application timezone.
 */
trait StoresUtcTimestamps
{
    /**
     * Convert a date-time to its stored UTC representation.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }

    /**
     * Interpret zone-less stored strings as UTC instead of the application timezone.
     *
     * @param  mixed  $value
     * @return Carbon
     */
    protected function asDateTime($value)
    {
        if (! is_string($value) || is_numeric($value) || $this->isStandardDateFormat($value)) {
            return parent::asDateTime($value);
        }

        try {
            $date = Date::createFromFormat($this->getDateFormat(), $value, 'UTC');
        } catch (InvalidArgumentException) {
            $date = null;
        }

        return ($date ?: Date::parse($value, 'UTC'))->utc();
    }
}
