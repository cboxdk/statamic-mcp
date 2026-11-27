<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Integration;

use Cboxdk\StatamicMcp\Mcp\Exceptions\FieldFormatException;
use Cboxdk\StatamicMcp\StatamicMcp;
use Statamic\Dictionaries\Countries;
use Statamic\Dictionaries\Item;
use Statamic\Fields\Field;

/** Optional reference integration; all access uses the public integration API. */
final class FormsPro
{
    private const ADDRESS_KEYS = ['line_1', 'line_2', 'city', 'region', 'postcode', 'country'];

    public static function register(): void
    {
        StatamicMcp::integration('statamic/forms-pro')
            ->scope('forms-pro:read', 'Read Forms Pro settings and page logic', ['access cp'])
            ->scope('forms-pro:write', 'Edit Forms Pro settings', ['access cp'])
            ->router(FormsProRouter::class)
            ->fieldtype('address', self::addressSpec(...), self::sanitizeAddress(...));
    }

    /** @return array<string, mixed> */
    public static function addressSpec(Field $field): array
    {
        return [
            'type' => 'object',
            'description' => 'Postal address. String or null values; country is an ISO 3166-1 alpha-2 code. Omitted keys are allowed; native required-field validation still applies.',
            'properties' => array_fill_keys(self::ADDRESS_KEYS, ['type' => ['string', 'null']]),
            'additionalProperties' => false,
            'default_country' => $field->get('default_country'),
            'example' => ['line_1' => 'Example Street 4', 'city' => 'Copenhagen', 'postcode' => '1000', 'country' => 'DK'],
        ];
    }

    public static function sanitizeAddress(mixed $value, Field $field, string $path): mixed
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || array_diff(array_keys($value), self::ADDRESS_KEYS) !== []) {
            throw new FieldFormatException("{$path} must be an address object with line_1, line_2, city, region, postcode and country keys only.");
        }
        foreach ($value as $key => $part) {
            if ($part !== null && ! is_string($part)) {
                throw new FieldFormatException("{$path}.{$key} must be text or null.");
            }
        }
        if (is_string($value['country'] ?? null) && $value['country'] !== '') {
            $country = strtoupper($value['country']);
            if (! collect((new Countries)->optionItems())->contains(fn (Item $item): bool => $item['iso2'] === $country)) {
                throw new FieldFormatException("{$path}.country must be an ISO 3166-1 alpha-2 country code.");
            }
            $value['country'] = $country;
        }

        return $value;
    }
}
