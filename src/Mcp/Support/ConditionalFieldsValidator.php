<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fields\Validator;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

/**
 * Statamic's Fields\Validator, minus the rules of fields hidden by their
 * `if` / `unless` conditions.
 *
 * The Control Panel evaluates conditions in JavaScript and leaves hidden
 * fields out of what it submits, so their rules never fire. Statamic's
 * server-side validator does not evaluate conditions, so every write through
 * this server enforced them anyway — a conditionally required field blocked
 * every create whose condition was not met. This validator evaluates the
 * conditions itself (see FieldConditions) and drops the rules of every
 * hidden field that has no value, at the top level and inside replicator,
 * bard, grid and group fields.
 *
 * A hidden field that does carry a value keeps its rules. The CP would never
 * submit it, but this server stores what it is sent, so skipping validation
 * there would let an invalid value in through the back door. Only rules are
 * dropped; values are untouched.
 */
class ConditionalFieldsValidator extends Validator
{
    /** @var array<mixed>|null */
    private ?array $conditionValues = null;

    /**
     * The values conditions are evaluated against.
     *
     * Conditions often target keys that are not blueprint fields — an entry's
     * `template` is the common one — so the record's full data is passed in
     * here. Without it, conditions see only the blueprint's own field values.
     *
     * @param  array<mixed>  $values
     */
    public function withConditionValues(array $values): static
    {
        $this->conditionValues = $values;

        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function rules()
    {
        $rules = parent::rules();

        if (! is_array($rules) || ! $this->fields instanceof Fields) {
            return is_array($rules) ? $rules : [];
        }

        $hidden = $this->hiddenPaths();

        if ($hidden === []) {
            return $rules;
        }

        return array_filter($rules, function (int|string $key) use ($hidden): bool {
            $key = (string) $key;
            foreach ($hidden as $path) {
                if ($key === $path || str_starts_with($key, $path . '.')) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Dotted rule paths of every empty field hidden by its conditions.
     *
     * @return list<string>
     */
    public function hiddenPaths(): array
    {
        if (! $this->fields instanceof Fields) {
            return [];
        }

        /** @var Fields $fields */
        $fields = $this->preProcessedFields();

        $own = $this->fieldValues($fields);
        $root = $this->conditionValues !== null ? array_merge($own, $this->conditionValues) : $own;

        return $this->collectHidden($fields, [$root], $own, '');
    }

    /**
     * @param  list<array<mixed>>  $scopes  What conditions read, root first
     * @param  array<mixed>  $own  This level's values as the validator sees them
     *
     * @return list<string>
     */
    private function collectHidden(Fields $fields, array $scopes, array $own, string $pathPrefix): array
    {
        $hidden = [];
        $all = $this->fieldList($fields);

        $revealers = [];
        foreach ($all as $field) {
            if ($field->type() === 'revealer') {
                $revealers[] = $field->handle();
            }
        }

        foreach ($all as $field) {
            $path = $pathPrefix . $field->handle();
            $prefix = $field->prefix();
            $value = $own[$field->handle()] ?? null;

            if (! FieldConditions::passes($field->config(), $scopes, $revealers, is_string($prefix) ? $prefix : null)
                && ($value === null || $value === '' || $value === [])) {
                $hidden[] = $path;

                continue;
            }

            foreach ($this->nestedScopes($field, $value) as [$nestedFields, $nestedValues, $nestedPath]) {
                array_push($hidden, ...$this->collectHidden(
                    $nestedFields,
                    [...$scopes, $nestedValues],
                    $nestedValues,
                    $path . '.' . ($nestedPath === '' ? '' : $nestedPath . '.')
                ));
            }
        }

        return $hidden;
    }

    /**
     * The child field sets of a replicator, bard, grid or group value, each
     * with its values and its path segment below the field — the same paths
     * those fieldtypes' extraRules() key their rules by.
     *
     * @return list<array{0: Fields, 1: array<mixed>, 2: string}>
     */
    private function nestedScopes(Field $field, mixed $value): array
    {
        $fieldtype = $field->fieldtype();
        $scopes = [];

        if ($fieldtype instanceof Group) {
            // Group rules are keyed `group.child`, with no index segment.
            return is_array($value) ? [[$fieldtype->fields(), $value, '']] : [];
        }

        if (! is_array($value)) {
            return [];
        }

        foreach ($value as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            if ($fieldtype instanceof Bard) {
                $attrs = $item['attrs'] ?? null;
                $values = is_array($attrs) ? ($attrs['values'] ?? null) : null;
                if (($item['type'] ?? null) !== 'set' || ! is_array($values) || ! is_string($values['type'] ?? null)) {
                    continue;
                }
                $scopes[] = [$fieldtype->fields($values['type'], $index), $values, "{$index}.attrs.values"];
            } elseif ($fieldtype instanceof Replicator) {
                if (! is_string($item['type'] ?? null)) {
                    continue;
                }
                $scopes[] = [$fieldtype->fields($item['type'], $index), $item, (string) $index];
            } elseif ($fieldtype instanceof Grid) {
                $scopes[] = [$fieldtype->fields($index), $item, (string) $index];
            }
        }

        return $scopes;
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldValues(Fields $fields): array
    {
        $values = [];
        foreach ($this->fieldList($fields) as $field) {
            $values[$field->handle()] = $field->value();
        }

        return $values;
    }

    /**
     * @return list<Field>
     */
    private function fieldList(Fields $fields): array
    {
        $list = [];
        foreach ($fields->all() as $field) {
            if ($field instanceof Field) {
                $list[] = $field;
            }
        }

        return $list;
    }
}
