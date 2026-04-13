<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

trait SanitizesFieldData
{
    /**
     * Keys that are entry-level properties, not blueprint data fields.
     *
     * @var array<int, string>
     */
    private static array $entryMetaKeys = ['blueprint', 'fieldset', 'id'];

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
     *
     * @return array<string, mixed>
     */
    private function sanitizeFieldCollection(Collection $fields, array $data, bool $allowLegacyCoercion, bool $stripEntryMeta): array
    {
        if ($stripEntryMeta) {
            foreach (self::$entryMetaKeys as $key) {
                if (! $fields->has($key)) {
                    unset($data[$key]);
                }
            }
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
            'table' => $this->sanitizeArrayValue('table', $value, $allowLegacyCoercion, $path),
            'terms', 'entries', 'users', 'assets', 'checkboxes' => $this->sanitizeRelationshipValue($value),
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

                throw new InvalidArgumentException("Field [{$path}.{$index}] references unknown Bard set [" . (is_scalar($setType) ? (string) $setType : 'invalid') . ']');
            }

            /** @var array<string, mixed> $bardSetValues */
            $bardSetValues = $values;

            /** @var array<string, mixed> $bardSetAttrs */
            $bardSetAttrs = $node['attrs'];
            $bardSetAttrs['values'] = $this->sanitizeFieldCollection(
                $fieldtype->fields($setType, (int) $index)->all(),
                $bardSetValues,
                $allowLegacyCoercion,
                false
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

        return $this->sanitizeFieldCollection($fieldtype->fields()->all(), $group, $allowLegacyCoercion, false);
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
                false
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

                throw new InvalidArgumentException("Field [{$path}.{$index}] references unknown replicator set [" . (is_scalar($setType) ? (string) $setType : 'invalid') . ']');
            }

            $sanitized[] = $this->sanitizeFieldCollection(
                $fieldtype->fields($setType, (int) $index)->all(),
                $row,
                $allowLegacyCoercion,
                false
            );
        }

        return $sanitized;
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

    private function invalidStructuredValue(string $path, string $fieldType, mixed $value): InvalidArgumentException
    {
        $received = get_debug_type($value);

        return new InvalidArgumentException("Field [{$path}] expects {$fieldType} data as an array, received {$received}.");
    }
}
