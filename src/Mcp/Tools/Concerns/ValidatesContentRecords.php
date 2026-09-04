<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Mcp\Validation\Finding;
use Cboxdk\StatamicMcp\Mcp\Validation\FindingType;
use Cboxdk\StatamicMcp\Mcp\Validation\RecordRef;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Asset;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fields\Fieldtype;

/**
 * Validates already-stored content against its blueprint.
 *
 * Writes go through the Control Panel's own validator, so anything created via
 * this addon is already checked. Content that arrives another way — a git merge,
 * a hand-edited YAML file, or a blueprint changed after the content was written —
 * is not. This trait provides the read-side sweep for exactly that drift.
 *
 * Every record gets two passes:
 *
 *  1. The blueprint's validation rules, evaluated the same way a Control Panel
 *     save evaluates them. Statamic's validator recurses into replicator and
 *     grid sets on its own, so nested rules are covered here too.
 *  2. Structural checks the rule engine cannot express: set types that are no
 *     longer defined, values stored under fields that were removed from the
 *     blueprint, option values outside the declared set, and asset references
 *     pointing at files that no longer exist. All four pass rule validation
 *     silently while breaking at render time.
 *
 * Pass 1 is deliberately isolated: a malformed stored value can make a
 * fieldtype's rule builder throw, and that must neither abort the sweep nor
 * hide the shape problem that pass 2 goes on to report.
 */
trait ValidatesContentRecords
{
    use ResolvesAssetIds;

    /** Keys a replicator/bard/grid row carries that are not blueprint fields. */
    private const SET_META_KEYS = ['id', 'type', 'enabled'];

    /** Fieldtypes whose stored value must appear in the declared options. */
    private const OPTION_FIELD_TYPES = ['select', 'radio', 'button_group', 'checkboxes'];

    /**
     * Validate one stored record against its blueprint fields.
     *
     * @param  array<string, mixed>  $data  The stored values
     *
     * @return list<Finding>
     */
    protected function validateRecord(Fields $fields, array $data, RecordRef $record): array
    {
        return [
            // The rule pass sees asset references bridged to the form the rules
            // expect; the structural pass sees the stored values verbatim, so a
            // finding still quotes what is actually on disk.
            ...$this->ruleFindings($fields, $this->withResolvedAssetIds($fields, $data), $record),
            ...$this->structuralFindings($fields, $data, '', $record),
        ];
    }

    /**
     * Run the blueprint's real validation rules against the stored values.
     *
     * @param  array<string, mixed>  $data
     *
     * @return list<Finding>
     */
    private function ruleFindings(Fields $fields, array $data, RecordRef $record): array
    {
        try {
            $fields->addValues($data)->validator()->validate();

            return [];
        } catch (ValidationException $e) {
            $findings = [];

            foreach ($e->errors() as $handle => $messages) {
                foreach ((array) $messages as $message) {
                    if (! is_string($message)) {
                        continue;
                    }

                    $findings[] = $this->buildFinding(
                        $record,
                        FindingType::RuleViolation,
                        $message,
                        is_string($handle) ? $handle : null
                    );
                }
            }

            return $findings;
        } catch (\Throwable $e) {
            // A malformed stored value can make a fieldtype's rule builder throw.
            // Surface it as a finding rather than swallowing it — the structural
            // pass still runs and usually names the underlying shape problem.
            return [$this->buildFinding(
                $record,
                FindingType::RuleEngineError,
                'Could not evaluate blueprint rules for this record: ' . $e->getMessage()
            )];
        }
    }

    /**
     * Walk resolved fields against stored values, reporting structural mismatches
     * the Control Panel would never let through.
     *
     * @param  array<string, mixed>  $data
     *
     * @return list<Finding>
     */
    private function structuralFindings(Fields $fields, array $data, string $path, RecordRef $record): array
    {
        $findings = [];

        foreach ($fields->all() as $handle => $field) {
            if (! is_string($handle) || ! $field instanceof Field) {
                continue;
            }

            if (! array_key_exists($handle, $data)) {
                continue;
            }

            $value = $data[$handle];
            $fieldPath = $path === '' ? $handle : "{$path}.{$handle}";
            $type = $field->type();

            if ($type === 'replicator') {
                $findings = [...$findings, ...$this->replicatorFindings($field, $value, $fieldPath, $record)];
            } elseif ($type === 'bard') {
                $findings = [...$findings, ...$this->bardFindings($field, $value, $fieldPath, $record)];
            } elseif ($type === 'grid') {
                $findings = [...$findings, ...$this->gridFindings($field, $value, $fieldPath, $record)];
            } elseif ($type === 'assets') {
                $findings = [...$findings, ...$this->assetFindings($field, $value, $fieldPath, $record)];
            } elseif (in_array($type, self::OPTION_FIELD_TYPES, true)) {
                $findings = [...$findings, ...$this->optionFindings($field, $value, $fieldPath, $record)];
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function replicatorFindings(Field $field, mixed $value, string $path, RecordRef $record): array
    {
        if (! is_array($value)) {
            return [];
        }

        $sets = $this->flattenedSets($field);
        $findings = [];

        foreach ($value as $index => $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $block */
            $findings = [...$findings, ...$this->setFindings($sets, $block, "{$path}.{$index}", $record)];
        }

        return $findings;
    }

    /**
     * Bard stores sets as ProseMirror nodes: the block's own values live under
     * `attrs.values`, with everything else being rich-text nodes we ignore.
     *
     * @return list<Finding>
     */
    private function bardFindings(Field $field, mixed $value, string $path, RecordRef $record): array
    {
        // A bard field with no sets is stored as an HTML string.
        if (! is_array($value)) {
            return [];
        }

        $sets = $this->flattenedSets($field);
        $findings = [];

        foreach ($value as $index => $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'set') {
                continue;
            }

            $attrs = $node['attrs'] ?? null;
            $values = is_array($attrs) ? ($attrs['values'] ?? null) : null;

            if (! is_array($values) || ! is_string($values['type'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $values */
            $findings = [...$findings, ...$this->setFindings($sets, $values, "{$path}.{$index}", $record)];
        }

        return $findings;
    }

    /**
     * Check one replicator/bard block against the set it claims to be.
     *
     * @param  array<string, list<mixed>>  $sets
     * @param  array<string, mixed>  $block
     *
     * @return list<Finding>
     */
    private function setFindings(array $sets, array $block, string $path, RecordRef $record): array
    {
        /** @var string $type */
        $type = $block['type'];

        if (! array_key_exists($type, $sets)) {
            return [$this->buildFinding(
                $record,
                FindingType::UnknownSetType,
                "Set '{$type}' is not defined on this field, so the block is dropped when rendered.",
                $path
            )];
        }

        $setFields = new Fields($sets[$type]);
        $findings = $this->unknownKeyFindings(
            $setFields,
            $block,
            $path,
            $record,
            fn (string $key): string => "Set '{$type}' stores '{$key}', which is not a field in its blueprint."
        );

        return [...$findings, ...$this->structuralFindings($setFields, $block, $path, $record)];
    }

    /**
     * @return list<Finding>
     */
    private function gridFindings(Field $field, mixed $value, string $path, RecordRef $record): array
    {
        $config = $field->get('fields');

        if (! is_array($value) || ! is_array($config)) {
            return [];
        }

        $rowFields = new Fields(array_values($config));
        $findings = [];

        foreach ($value as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $rowPath = "{$path}.{$index}";

            $findings = [
                ...$findings,
                ...$this->unknownKeyFindings(
                    $rowFields,
                    $row,
                    $rowPath,
                    $record,
                    fn (string $key): string => "Grid row stores '{$key}', which is not a field in the grid's blueprint."
                ),
                ...$this->structuralFindings($rowFields, $row, $rowPath, $record),
            ];
        }

        return $findings;
    }

    /**
     * Report stored keys that no longer correspond to a field in the blueprint.
     *
     * Only applied inside sets and grid rows. Entries carry plenty of legitimate
     * non-blueprint keys at the top level (template, layout, parent, …), so the
     * same check there would be pure noise.
     *
     * @param  array<string, mixed>  $stored
     * @param  callable(string): string  $message
     *
     * @return list<Finding>
     */
    private function unknownKeyFindings(Fields $fields, array $stored, string $path, RecordRef $record, callable $message): array
    {
        /** @var list<string> $known */
        $known = [...self::SET_META_KEYS, ...$fields->all()->keys()->all()];
        $findings = [];

        foreach (array_keys($stored) as $key) {
            $key = (string) $key;

            if (in_array($key, $known, true)) {
                continue;
            }

            $findings[] = $this->buildFinding(
                $record,
                FindingType::UnknownField,
                $message($key),
                "{$path}.{$key}"
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function optionFindings(Field $field, mixed $value, string $path, RecordRef $record): array
    {
        $options = $field->get('options');

        if (! is_array($options) || $options === []) {
            return [];
        }

        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        // Options are either a list of values or a value => label map.
        $keys = array_is_list($options) ? $options : array_keys($options);

        // YAML hands back numeric option keys as ints while the stored value is
        // usually a string, so compare as strings instead of reporting a
        // false positive on every numerically-keyed select.
        $declared = [];
        foreach ($keys as $key) {
            if (is_scalar($key)) {
                $declared[] = (string) $key;
            }
        }

        $findings = [];

        // Single-value fields hold a scalar, `multiple` ones hold a list.
        foreach (is_array($value) ? $value : [$value] as $selected) {
            if ($selected === null || $selected === '') {
                continue;
            }

            if (! is_scalar($selected)) {
                $findings[] = $this->buildFinding(
                    $record,
                    FindingType::InvalidOption,
                    'Field stores a ' . gettype($selected) . ' where an option value is expected.',
                    $path
                );

                continue;
            }

            if (! in_array((string) $selected, $declared, true)) {
                $findings[] = $this->buildFinding(
                    $record,
                    FindingType::InvalidOption,
                    "Value '" . (string) $selected . "' is not one of the options declared on this field.",
                    $path
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function assetFindings(Field $field, mixed $value, string $path, RecordRef $record): array
    {
        $container = $this->assetFieldContainer($field);

        // Without a container we cannot resolve the reference at all; that is a
        // blueprint problem, which statamic-blueprints validate already reports.
        if ($container === null) {
            return [];
        }

        $findings = [];

        foreach (is_array($value) ? $value : [$value] as $reference) {
            if (! is_string($reference) || $reference === '') {
                continue;
            }

            $id = str_contains($reference, '::') ? $reference : "{$container}::{$reference}";

            if (Asset::find($id) !== null) {
                continue;
            }

            $findings[] = $this->buildFinding(
                $record,
                FindingType::MissingAsset,
                "References asset '{$reference}', which does not exist in container '{$container}'.",
                $path
            );
        }

        return $findings;
    }

    /**
     * Return a copy of the stored values with every assets reference rewritten
     * to the canonical ID the validation rules expect.
     *
     * Statamic's validator recurses into nested fields on its own, but it does
     * so over whatever values it was handed, so the bridge has to be applied to
     * the whole tree up front. Nothing but assets values is touched.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    private function withResolvedAssetIds(Fields $fields, array $data): array
    {
        foreach ($fields->all() as $handle => $field) {
            if (! is_string($handle) || ! $field instanceof Field || ! array_key_exists($handle, $data)) {
                continue;
            }

            $value = $data[$handle];

            $data[$handle] = match ($field->type()) {
                'assets' => $this->normalizeAssetFieldValue($field, $value),
                'replicator' => $this->withResolvedAssetIdsInSets($field, $value),
                'bard' => $this->withResolvedAssetIdsInBard($field, $value),
                'grid' => $this->withResolvedAssetIdsInRows($field, $value),
                'group' => is_array($value)
                    ? $this->withResolvedAssetIds($this->nestedFields($field), $this->stringKeyed($value))
                    : $value,
                default => $value,
            };
        }

        return $data;
    }

    private function withResolvedAssetIdsInSets(Field $field, mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sets = $this->flattenedSets($field);

        return array_map(function (mixed $block) use ($sets): mixed {
            if (! is_array($block) || ! is_string($type = $block['type'] ?? null) || ! isset($sets[$type])) {
                return $block;
            }

            return $this->withResolvedAssetIds(new Fields($sets[$type]), $this->stringKeyed($block));
        }, $value);
    }

    /**
     * Bard keeps a set's own values under `attrs.values`; the rest of the tree
     * is rich-text nodes with nothing to resolve.
     */
    private function withResolvedAssetIdsInBard(Field $field, mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sets = $this->flattenedSets($field);

        return array_map(function (mixed $node) use ($sets): mixed {
            if (! is_array($node) || ($node['type'] ?? null) !== 'set') {
                return $node;
            }

            $attrs = $node['attrs'] ?? null;
            $values = is_array($attrs) ? ($attrs['values'] ?? null) : null;

            if (! is_array($values) || ! is_string($type = $values['type'] ?? null) || ! isset($sets[$type])) {
                return $node;
            }

            $attrs['values'] = $this->withResolvedAssetIds(new Fields($sets[$type]), $this->stringKeyed($values));
            $node['attrs'] = $attrs;

            return $node;
        }, $value);
    }

    private function withResolvedAssetIdsInRows(Field $field, mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $fields = $this->nestedFields($field);

        return array_map(
            fn (mixed $row): mixed => is_array($row)
                ? $this->withResolvedAssetIds($fields, $this->stringKeyed($row))
                : $row,
            $value
        );
    }

    /**
     * Resolve the child fields of a grid or group field.
     */
    private function nestedFields(Field $field): Fields
    {
        $config = $field->get('fields');

        return new Fields(is_array($config) ? array_values($config) : []);
    }

    /**
     * Re-key a nested block or row so it carries the handle-keyed shape the
     * walk expects. YAML can hand back numeric-looking keys, which PHP casts
     * to integers on the way in.
     *
     * @param  array<mixed>  $value
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $keyed = [];

        foreach ($value as $key => $item) {
            $keyed[(string) $key] = $item;
        }

        return $keyed;
    }

    /**
     * Resolve a replicator/bard field's sets to [set handle => field configs].
     *
     * Statamic nests sets under a group key in the current format and stores
     * them flat in the legacy one. The fieldtype already normalises both, so
     * defer to it and only flatten by hand when it cannot be reached.
     *
     * @return array<string, list<mixed>>
     */
    private function flattenedSets(Field $field): array
    {
        try {
            $fieldtype = $field->fieldtype();

            if ($fieldtype instanceof Fieldtype && method_exists($fieldtype, 'flattenedSetsConfig')) {
                /** @var iterable<string, mixed> $config */
                $config = $fieldtype->flattenedSetsConfig();

                $sets = [];
                foreach ($config as $handle => $set) {
                    $sets[(string) $handle] = $this->setFieldConfig($set);
                }

                return $sets;
            }
        } catch (\Throwable) {
            // Unregistered fieldtype or a config shape it cannot digest —
            // fall through to the manual flatten below.
        }

        return $this->flattenSetsConfig($field->get('sets'));
    }

    /**
     * Flatten a raw `sets` config, handling both the grouped and legacy formats.
     *
     * @return array<string, list<mixed>>
     */
    private function flattenSetsConfig(mixed $sets): array
    {
        if (! is_array($sets)) {
            return [];
        }

        $flat = [];

        foreach ($sets as $handle => $config) {
            if (! is_array($config)) {
                continue;
            }

            $grouped = $config['sets'] ?? null;

            if (is_array($grouped)) {
                foreach ($grouped as $setHandle => $setConfig) {
                    $flat[(string) $setHandle] = $this->setFieldConfig($setConfig);
                }

                continue;
            }

            $flat[(string) $handle] = $this->setFieldConfig($config);
        }

        return $flat;
    }

    /**
     * @return list<mixed>
     */
    private function setFieldConfig(mixed $set): array
    {
        if (! is_array($set)) {
            return [];
        }

        $fields = $set['fields'] ?? null;

        return is_array($fields) ? array_values($fields) : [];
    }

    private function buildFinding(RecordRef $record, FindingType $type, string $message, ?string $fieldPath = null): Finding
    {
        return new Finding($type, $record, $message, $fieldPath);
    }
}
