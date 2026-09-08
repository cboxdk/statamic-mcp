<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Mcp\Exceptions\FieldFormatException;
use Illuminate\Support\Collection;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

trait SanitizesFieldData
{
    use ResolvesAssetIds;

    /**
     * Keys that are entry-level properties, not blueprint data fields.
     *
     * @var array<int, string>
     */
    private static array $entryMetaKeys = ['blueprint', 'fieldset', 'id'];

    /**
     * Record properties a caller may send alongside field data: the routers
     * consume some before sanitising, and a payload round-tripped from a read
     * carries the rest.
     *
     * @var array<int, string>
     */
    private const RECORD_PROPERTY_KEYS = [
        'id', 'blueprint', 'fieldset', 'slug', 'published', 'date',
        'created_at', 'created_by', 'updated_at', 'updated_by',
        'origin', 'locale', 'site', 'collection', 'taxonomy', '_id',
    ];

    /** Structural keys on a replicator item (see FieldFormatSpec: id, type, enabled). */
    private const REPLICATOR_ITEM_KEYS = ['id', '_id', 'type', 'enabled'];

    /** Structural keys on a grid row. */
    private const GRID_ROW_KEYS = ['id', '_id'];

    /** `type` inside a bard set's values names the set, it is not a field handle. */
    private const BARD_SET_VALUE_KEYS = ['type'];

    /** Cap on handles listed back in an unknown-field error, so the message stays readable. */
    private const MAX_LISTED_HANDLES = 60;

    /**
     * Sanitize client-provided field data before passing it to validation.
     *
     * This strips reserved entry metadata keys only when they are not real
     * field handles, recursively normalizes Bard strings, and rejects invalid
     * scalar payloads for structured fieldtypes before they can clear content.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    protected function sanitizeIncomingFieldData(Blueprint $blueprint, array $data): array
    {
        /** @var Collection<string, Field> $fields */
        $fields = $blueprint->fields()->all();

        return $this->sanitizeFieldCollection($fields, $data, false, true);
    }

    /**
     * Sanitize persisted field data only for validation (backward compat).
     *
     * Content saved by this addon prior to v2.1 was stored without the
     * fieldtype process() step, so structured fields may contain raw
     * strings that crash Statamic's preProcessValidatable() pipeline.
     * This normalizes them in-memory so validation can proceed.
     *
     * Safe to remove once all MCP-created content has been re-saved
     * through a version that includes the process() pipeline fix.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     *
     * @deprecated Will be removed in a future major version.
     */
    protected function sanitizeStoredFieldDataForValidation(Blueprint $blueprint, array $data): array
    {
        /** @var Collection<string, Field> $fields */
        $fields = $blueprint->fields()->all();

        return $this->sanitizeFieldCollection($fields, $data, true, true);
    }

    /**
     * @param  Collection<string, Field>  $fields
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $structuralKeys
     *
     * @return array<string, mixed>
     */
    private function sanitizeFieldCollection(
        Collection $fields,
        array $data,
        bool $allowLegacyCoercion,
        bool $stripEntryMeta,
        string $path = '',
        array $structuralKeys = self::RECORD_PROPERTY_KEYS
    ): array {
        if ($stripEntryMeta) {
            foreach (self::$entryMetaKeys as $key) {
                if (! $fields->has($key)) {
                    unset($data[$key]);
                }
            }
        }

        // Stored data being re-validated may carry handles from an older
        // blueprint; only incoming payloads are held to the current one.
        if (! $allowLegacyCoercion) {
            $this->assertKnownHandles($fields, $data, $path, $structuralKeys);
        }

        foreach ($fields as $handle => $field) {
            if (! array_key_exists($handle, $data)) {
                continue;
            }

            $data[$handle] = $this->sanitizeFieldValue(
                $field,
                $data[$handle],
                $allowLegacyCoercion,
                $handle
            );
        }

        return $data;
    }

    /**
     * Reject keys that are not field handles at this level.
     *
     * Statamic stays quiet two different ways: Fields::addValues() reads only
     * handles it knows, discarding stray top-level keys, while Replicator and
     * Grid processRow() merge the raw row back over the processed one, storing
     * stray keys inside a set as inert data. Both report success.
     *
     * @param  Collection<string, Field>  $fields
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $structuralKeys
     *
     * @throws FieldFormatException
     */
    private function assertKnownHandles(Collection $fields, array $data, string $path, array $structuralKeys): void
    {
        if (! config('statamic.mcp.security.reject_unknown_fields', true)) {
            return;
        }

        /** @var array<int, string> $known */
        $known = $fields->keys()->all();

        $unknown = array_values(array_diff(array_keys($data), $known, $structuralKeys));

        if ($unknown === []) {
            return;
        }

        sort($known);
        $listed = array_slice($known, 0, self::MAX_LISTED_HANDLES);
        $suffix = count($known) > self::MAX_LISTED_HANDLES
            ? ' (+' . (count($known) - self::MAX_LISTED_HANDLES) . ' more)'
            : '';

        throw new FieldFormatException(sprintf(
            '%s %s not %s in %s. Statamic does not error on unrecognised keys — it discards them at the top level and stores them as inert data inside replicator, grid and bard sets — so this write would have reported success while silently not producing the requested content. Valid handles here: %s%s.',
            count($unknown) === 1 ? 'Field' : 'Fields',
            implode(', ', array_map(static fn (string $key): string => "[{$key}]", $unknown)),
            count($unknown) === 1 ? 'a field' : 'fields',
            $path === '' ? 'this blueprint' : "[{$path}]",
            $listed === [] ? '(none)' : implode(', ', $listed),
            $suffix
        ));
    }

    /**
     * Coerce one stored value into the shape its fieldtype expects.
     *
     * Both the input and the result are genuinely `mixed`: the value comes from
     * stored YAML or a client payload, and a fieldtype may hand back any shape
     * it considers valid. It is narrowed by the caller at the storage boundary.
     */
    private function sanitizeFieldValue(Field $field, mixed $value, bool $allowLegacyCoercion, string $path): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field->type()) {
            'bard' => $this->sanitizeBardValue($field, $value, $allowLegacyCoercion, $path),
            'group' => $this->sanitizeGroupValue($field, $value, $allowLegacyCoercion, $path),
            'grid' => $this->sanitizeGridValue($field, $value, $allowLegacyCoercion, $path),
            'replicator' => $this->sanitizeReplicatorValue($field, $value, $allowLegacyCoercion, $path),
            'table' => $this->sanitizeTableValue($value, $allowLegacyCoercion, $path),
            'assets' => $this->normalizeAssetFieldValue($field, $value),
            'terms', 'entries', 'users', 'checkboxes' => $this->sanitizeRelationshipValue($value),
            default => $value,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBardValue(Field $field, mixed $value, bool $allowLegacyCoercion, string $path): array
    {
        if (is_string($value)) {
            return [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => $value],
                    ],
                ],
            ];
        }

        $nodes = $this->sanitizeArrayValue('bard', $value, $allowLegacyCoercion, $path);
        $fieldtype = $field->fieldtype();

        if (! $fieldtype instanceof Bard) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $sanitized */
        $sanitized = [];

        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                if ($allowLegacyCoercion) {
                    continue;
                }

                throw $this->invalidStructuredValue($path . '.' . $index, 'bard node', $node);
            }

            if (($node['type'] ?? null) !== 'set') {
                $sanitized[] = $node;
                continue;
            }

            $attrs = $node['attrs'] ?? null;
            $values = is_array($attrs) ? ($attrs['values'] ?? null) : null;
            if (! is_array($values)) {
                if ($allowLegacyCoercion) {
                    continue;
                }

                throw $this->invalidStructuredValue($path . '.' . $index . '.attrs.values', 'bard set values', $values);
            }

            $setType = $values['type'] ?? null;
            if (! is_string($setType) || ! $fieldtype->flattenedSetsConfig()->has($setType)) {
                if ($allowLegacyCoercion) {
                    continue;
                }

                throw new FieldFormatException("Field [{$path}.{$index}] references unknown Bard set [" . (is_scalar($setType) ? (string) $setType : 'invalid') . ']');
            }

            /** @var array<string, mixed> $bardSetValues */
            $bardSetValues = $values;

            /** @var array<string, mixed> $bardSetAttrs */
            $bardSetAttrs = $node['attrs'];
            $bardSetAttrs['values'] = $this->sanitizeFieldCollection(
                $fieldtype->fields($setType, (int) $index)->all(),
                $bardSetValues,
                $allowLegacyCoercion,
                false,
                $path . '.' . $index . '.attrs.values',
                self::BARD_SET_VALUE_KEYS
            );
            $node['attrs'] = $bardSetAttrs;

            $sanitized[] = $node;
        }

        return $sanitized;
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeGroupValue(Field $field, mixed $value, bool $allowLegacyCoercion, string $path): array
    {
        $group = $this->sanitizeArrayValue('group', $value, $allowLegacyCoercion, $path);
        $fieldtype = $field->fieldtype();

        if (! $fieldtype instanceof Group) {
            return [];
        }

        return $this->sanitizeFieldCollection($fieldtype->fields()->all(), $group, $allowLegacyCoercion, false, $path, []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeGridValue(Field $field, mixed $value, bool $allowLegacyCoercion, string $path): array
    {
        $rows = $this->sanitizeArrayValue('grid', $value, $allowLegacyCoercion, $path);
        $fieldtype = $field->fieldtype();

        if (! $fieldtype instanceof Grid) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $sanitized */
        $sanitized = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                if ($allowLegacyCoercion) {
                    continue;
                }

                throw $this->invalidStructuredValue($path . '.' . $index, 'grid row', $row);
            }

            /** @var array<string, mixed> $gridRow */
            $gridRow = $row;

            $sanitized[] = $this->sanitizeFieldCollection(
                $fieldtype->fields((int) $index)->all(),
                $gridRow,
                $allowLegacyCoercion,
                false,
                $path . '.' . $index,
                self::GRID_ROW_KEYS
            );
        }

        return $sanitized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeReplicatorValue(Field $field, mixed $value, bool $allowLegacyCoercion, string $path): array
    {
        $rows = $this->sanitizeArrayValue('replicator', $value, $allowLegacyCoercion, $path);
        $fieldtype = $field->fieldtype();

        if (! $fieldtype instanceof Replicator) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $sanitized */
        $sanitized = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                if ($allowLegacyCoercion) {
                    continue;
                }

                throw $this->invalidStructuredValue($path . '.' . $index, 'replicator set', $row);
            }

            $setType = $row['type'] ?? null;
            if (! is_string($setType) || ! $fieldtype->flattenedSetsConfig()->has($setType)) {
                if ($allowLegacyCoercion) {
                    continue;
                }

                throw new FieldFormatException("Field [{$path}.{$index}] references unknown replicator set [" . (is_scalar($setType) ? (string) $setType : 'invalid') . ']');
            }

            $sanitized[] = $this->sanitizeFieldCollection(
                $fieldtype->fields($setType, (int) $index)->all(),
                $row,
                $allowLegacyCoercion,
                false,
                $path . '.' . $index,
                self::REPLICATOR_ITEM_KEYS
            );
        }

        return $sanitized;
    }

    /**
     * Normalize Statamic table field storage: each row is ['cells' => [scalar|null, ...]].
     *
     * LLMs (and augmented template output) commonly wrap cells as ['value' => scalar].
     * That form renders fine on the frontend but breaks the CP editor, which reads raw
     * storage and stringifies objects to "[object Object]". Unwrap the common augmented
     * form; reject anything else so the write fails loudly instead of corrupting content.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeTableValue(mixed $value, bool $allowLegacyCoercion, string $path): array
    {
        $rows = $this->sanitizeArrayValue('table', $value, $allowLegacyCoercion, $path);

        /** @var array<int, array<string, mixed>> $sanitized */
        $sanitized = [];

        foreach ($rows as $rowIndex => $row) {
            if (! is_array($row)) {
                if ($allowLegacyCoercion) {
                    continue;
                }

                throw $this->invalidStructuredValue($path . '.' . $rowIndex, 'table row', $row);
            }

            $cells = $row['cells'] ?? null;
            if (! is_array($cells)) {
                if ($allowLegacyCoercion) {
                    continue;
                }

                throw $this->invalidStructuredValue($path . '.' . $rowIndex . '.cells', 'table cells', $cells);
            }

            /** @var array<int, string|null> $normalizedCells */
            $normalizedCells = [];
            foreach (array_values($cells) as $cellIndex => $cell) {
                $normalizedCells[] = $this->normalizeTableCell(
                    $cell,
                    $allowLegacyCoercion,
                    $path . '.' . $rowIndex . '.cells.' . $cellIndex
                );
            }

            $row['cells'] = $normalizedCells;

            /** @var array<string, mixed> $sanitizedRow */
            $sanitizedRow = $row;
            $sanitized[] = $sanitizedRow;
        }

        return $sanitized;
    }

    /**
     * Coerce a single table cell to string|null, unwrapping the augmented ['value' => x] form.
     */
    private function normalizeTableCell(mixed $cell, bool $allowLegacyCoercion, string $path): ?string
    {
        if ($cell === null) {
            return null;
        }

        if (is_string($cell)) {
            return $cell;
        }

        if (is_int($cell) || is_float($cell) || is_bool($cell)) {
            return (string) $cell;
        }

        if (is_array($cell) && array_key_exists('value', $cell)) {
            $inner = $cell['value'];

            if ($inner === null) {
                return null;
            }

            if (is_string($inner)) {
                return $inner;
            }

            if (is_int($inner) || is_float($inner) || is_bool($inner)) {
                return (string) $inner;
            }

            // Nested array — do not recurse further to prevent stack overflow
            if ($allowLegacyCoercion) {
                return null;
            }

            throw new FieldFormatException(
                "Field [{$path}] table cell['value'] must be a scalar or null, received " . get_debug_type($inner) . '.'
            );
        }

        if ($allowLegacyCoercion) {
            return null;
        }

        throw new FieldFormatException(
            "Field [{$path}] table cell must be a string or null, received " . get_debug_type($cell) . '.'
        );
    }

    /**
     * @return array<mixed>
     */
    private function sanitizeArrayValue(string $fieldType, mixed $value, bool $allowLegacyCoercion, string $path): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($allowLegacyCoercion) {
            return [];
        }

        throw $this->invalidStructuredValue($path, $fieldType, $value);
    }

    /**
     * Normalize relationship field values (terms, entries, users, assets).
     *
     * These fieldtypes expect arrays but LLMs may send a bare string.
     *
     * @return array<int, mixed>
     */
    private function sanitizeRelationshipValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return [];
    }

    private function invalidStructuredValue(string $path, string $fieldType, mixed $value): FieldFormatException
    {
        $received = get_debug_type($value);

        return new FieldFormatException("Field [{$path}] expects {$fieldType} data as an array, received {$received}.");
    }
}
