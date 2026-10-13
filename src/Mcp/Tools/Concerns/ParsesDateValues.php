<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

trait NormalizesDateFields
{
    /**
     * Normalize date field values in data to the format Statamic's FieldsValidator expects.
     *
     * Statamic's DateFieldtype validation requires: Y-m-d\TH:i:s.v\Z (ISO 8601 Zulu with milliseconds).
     * LLMs may send dates as Y-m-d, Y-m-d H:i, ISO 8601, or {date, time} objects.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    protected function normalizeDateFields(Blueprint $blueprint, array $data): array
    {
        /** @var Collection<string, Field> $fields */
        $fields = $blueprint->fields()->all();

        foreach ($fields as $handle => $field) {
            if ($field->type() !== 'date' || ! array_key_exists($handle, $data)) {
                continue;
            }

            $value = $data[$handle];
            if ($value === null || $value === '') {
                continue;
            }

            try {
                $carbon = $this->parseDateValue($value);
                $data[$handle] = $carbon->format('Y-m-d\TH:i:s.v\Z');
            } catch (\Throwable) {
                // Leave the value as-is; the FieldsValidator will report the error
            }
        }

        return $data;
    }

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
