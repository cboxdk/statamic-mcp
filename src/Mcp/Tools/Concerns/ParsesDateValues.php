<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Carbon\Carbon;

/**
 * Parses the entry `date` property, which is not a blueprint field.
 *
 * Blueprint date fields are handled by SanitizesFieldData::sanitizeDateValue()
 * at every level of a record. There used to be a second, top-level pass here;
 * it formatted the app-timezone wall clock with a literal `Z`, which shifted
 * every time on a non-UTC site, and it took a `{start, end}` range for a
 * `{date, time}` object. Do not bring it back.
 */
trait ParsesDateValues
{
    /**
     * Parse a date value from various formats into a Carbon instance.
     *
     * Accepts: ISO 8601, Y-m-d, Y-m-d H:i, Carbon instance, or {date, time} array.
     */
    protected function parseDateValue(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_array($value)) {
            $date = is_string($value['date'] ?? null) ? $value['date'] : '';
            $time = is_string($value['time'] ?? null) ? $value['time'] : '00:00';

            return Carbon::parse("{$date} {$time}");
        }

        if (is_string($value)) {
            return Carbon::parse($value);
        }

        throw new \InvalidArgumentException('Date must be a string, Carbon instance, or {date, time} array.');
    }
}
