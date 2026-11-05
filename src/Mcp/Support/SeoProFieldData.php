<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Cboxdk\StatamicMcp\Mcp\Exceptions\FieldFormatException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\SeoPro\Fields as SeoFields;

/** Converts SEO Pro's stored representation to its publish-form input. */
final class SeoProFieldData
{
    /**
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $stored
     *
     * @return array<string, mixed>
     */
    public static function merge(Blueprint $blueprint, array $incoming, array $stored): array
    {
        foreach ($blueprint->fields()->all() as $handle => $field) {
            if ($field instanceof Field && $field->type() === 'seo_pro' && isset($incoming[$handle]) && is_array($incoming[$handle]) && isset($stored[$handle]) && is_array($stored[$handle])) {
                // Each SEO handle is atomic: lists and custom objects must be replaced, not recursively merged.
                $incoming[$handle] = array_replace($stored[$handle], $incoming[$handle]);
            }
        }

        return $incoming;
    }

    /**
     * Restore omitted handles verbatim: SEO Pro's preProcess/process round trip
     * can normalize legacy values and empty strings even when they were untouched.
     *
     * @param  array<string, mixed>  $processed
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $stored
     *
     * @return array<string, mixed>
     */
    public static function preserveOmitted(Blueprint $blueprint, array $processed, array $incoming, array $stored): array
    {
        foreach ($blueprint->fields()->all() as $handle => $field) {
            if (! $field instanceof Field || $field->type() !== 'seo_pro'
                || ! is_array($incoming[$handle] ?? null)
                || ! is_array($stored[$handle] ?? null)
                || ! is_array($processed[$handle] ?? null)) {
                continue;
            }
            $processed[$handle] = array_replace(
                array_diff_key($stored[$handle], $incoming[$handle]),
                array_intersect_key($processed[$handle], $incoming[$handle])
            );
        }

        return $processed;
    }

    public static function sanitize(Field $field, mixed $value, string $path): mixed
    {
        if ($field->type() === 'seo_pro_source') {
            return self::source($field, $value, $path);
        }
        if ($value === false) {
            return ['enabled' => false];
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new FieldFormatException("Field [{$path}] expects an SEO object or false to explicitly disable SEO.");
        }
        if (array_key_exists('enabled', $value) && ! is_bool($value['enabled'])) {
            throw new FieldFormatException("Field [{$path}.enabled] expects a boolean.");
        }
        $fields = self::fields($field)->all();
        $result = ['enabled' => $value['enabled'] ?? true];
        foreach ($value as $handle => $childValue) {
            if ($handle === 'enabled') {
                continue;
            }
            $child = $fields->get($handle);
            if (! $child instanceof Field || $child->type() === 'section') {
                throw new FieldFormatException("Unknown SEO field [{$path}.{$handle}].");
            }
            $result[$handle] = $child->type() === 'seo_pro_source'
                ? self::source($child, $childValue, "{$path}.{$handle}")
                : $childValue;
        }

        return $result;
    }

    private static function source(Field $field, mixed $value, string $path): mixed
    {
        // Accept publish-form input as well, including a second validation pass.
        if (is_array($value) && array_key_exists('source', $value)) {
            if (! in_array($value['source'], ['custom', 'field', 'inherit', 'disable'], true)
                || ! array_key_exists('value', $value)
                || array_diff(array_keys($value), ['source', 'value']) !== []) {
                throw new FieldFormatException("Field [{$path}] expects a valid {source, value} pair.");
            }
            if ($value['source'] === 'disable' && $field->get('disableable') !== true) {
                throw new FieldFormatException("Field [{$path}] does not support disabling.");
            }
            if ($value['source'] === 'field' && ! is_string($value['value'])) {
                throw new FieldFormatException("Field [{$path}] expects a field handle string.");
            }

            if ($value['source'] === 'custom') {
                self::validateSourceValue($field, $value['value'], $path);
            }

            return $value;
        }

        if ($value !== false) {
            self::validateSourceValue($field, $value, $path);
        }

        return $field->setValue($value)->preProcess()->value();
    }

    private static function validateSourceValue(Field $field, mixed $value, string $path): void
    {
        $config = $field->get('field');
        if (is_array($config) && in_array($config['type'] ?? null, ['text', 'textarea'], true)
            && $value !== null && ! is_string($value)) {
            throw new FieldFormatException("Field [{$path}] expects a string, a field reference, null to inherit, or false to disable.");
        }
    }

    /** @return array<string, mixed> */
    public static function spec(Field $field): array
    {
        $handles = [];
        if ($field->type() === 'seo_pro' && class_exists(SeoFields::class)) {
            $handles = self::fields($field)->all()->keys()->all();
        }

        return [
            'handles' => $handles,
            'wire_format' => $field->type() === 'seo_pro' ? 'object_or_false' : 'seo_source',
            'shape' => $field->type(),
            'rules' => [
                'Use stored SEO values: plain values, @seo:field references, null to inherit, or false for a disableable source.',
                'SEO object updates preserve omitted handles. Each supplied handle replaces its previous value.',
                'Only seo: false or enabled: false explicitly disables SEO. Object writes default to enabled.',
                'Control Panel {source, value} pairs are also accepted.',
            ],
            'examples' => [['title' => 'Page title', 'description' => 'Page description']],
        ];
    }

    private static function fields(Field $field): Fields
    {
        if (! class_exists(SeoFields::class)) {
            throw new FieldFormatException('SEO Pro must be installed to write SEO Pro fields.');
        }
        $parent = $field->parent();
        $config = SeoFields::new($parent instanceof Entry || $parent instanceof Term ? $parent : null)->getConfig();
        $definitions = [];
        foreach ($config as $section) {
            foreach ($section['fields'] as $definition) {
                $definitions[] = $definition;
            }
        }

        return (new Fields($definitions))->setParent($parent);
    }
}
