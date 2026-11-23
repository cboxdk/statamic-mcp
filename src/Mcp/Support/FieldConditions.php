<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

/**
 * Server-side evaluation of a field's `if` / `unless` conditions.
 *
 * Statamic only evaluates field conditions in the Control Panel's JavaScript
 * (resources/js/components/field-conditions/Validator.js). A field hidden by
 * its conditions is left out of the payload the CP submits, so its `required`
 * rule never sees it. The server-side Fields\Validator has no notion of
 * conditions at all, so a client that does not run that JavaScript — this MCP
 * server — has every hidden required field enforced.
 *
 * This is a port of that JavaScript, keeping its comparison semantics (loose
 * equality, trimmed strings, `empty`, `null`/`true`/`false` literals). Two
 * things cannot be evaluated here and count as passing, so the field keeps
 * being validated as it was before: `custom` conditions (they are JavaScript
 * functions registered in the CP) and conditions on a revealer field (a
 * revealer has no stored value; the CP keeps validating the fields it hides).
 */
final class FieldConditions
{
    private const KEYS = ['if', 'if_any', 'show_when', 'show_when_any', 'unless', 'unless_any', 'hide_when', 'hide_when_any'];

    private const OPERATORS = ['equals', 'not', 'contains', 'contains_any', 'includes_any', '===', '!==', '>', '>=', '<', '<=', 'custom'];

    private const ALIASES = ['is' => 'equals', '==' => 'equals', 'isnt' => 'not', '!=' => 'not', 'includes' => 'contains'];

    private const NUMERIC_OPERATORS = ['>', '>=', '<', '<='];

    /**
     * Whether a field with this config is shown for these values.
     *
     * @param  array<string, mixed>  $config  The field's config (holds the condition keys)
     * @param  list<array<mixed>>  $scopes  Value scopes, outermost (root) first, the field's own scope last
     * @param  list<string>  $revealers  Handles of revealer fields in the field's own scope
     */
    public static function passes(array $config, array $scopes, array $revealers = [], ?string $prefix = null): bool
    {
        $key = null;
        foreach (self::KEYS as $candidate) {
            if (! empty($config[$candidate])) {
                $key = $candidate;
                break;
            }
        }

        if ($key === null) {
            return true;
        }

        $conditions = $config[$key];

        // A bare string is a custom condition with no target field.
        if (! is_array($conditions)) {
            return true;
        }

        $passOnAny = str_contains($key, 'any');
        $showOnPass = ! str_contains($key, 'unless') && ! str_contains($key, 'hide_when');

        $results = [];
        foreach ($conditions as $field => $rhsList) {
            if (! is_string($field)) {
                continue;
            }

            if (in_array($field, $revealers, true)) {
                continue;
            }

            foreach (is_array($rhsList) ? $rhsList : [$rhsList] as $rhs) {
                $result = self::passesCondition(self::scopedHandle($field, $prefix), $rhs, $scopes);

                if ($result !== null) {
                    $results[] = $result;
                }
            }
        }

        if ($results === []) {
            // Nothing evaluable (only custom or revealer conditions): treat
            // as visible so validation is never loosened on a guess.
            return true;
        }

        $passes = $passOnAny ? in_array(true, $results, true) : ! in_array(false, $results, true);

        return $showOnPass ? $passes : ! $passes;
    }

    private static function scopedHandle(string $field, ?string $prefix): string
    {
        if (str_starts_with($field, '$root.') || str_starts_with($field, 'root.') || str_starts_with($field, '$parent.')) {
            return $field;
        }

        return ($prefix ?? '') . $field;
    }

    /**
     * @param  list<array<mixed>>  $scopes
     *
     * @return bool|null Null when the condition cannot be evaluated server-side
     */
    private static function passesCondition(string $field, mixed $condition, array $scopes): ?bool
    {
        $rhsString = self::normalizeConditionString($condition);

        $operator = '==';
        $value = $rhsString;
        foreach (array_merge(self::OPERATORS, array_keys(self::ALIASES)) as $candidate) {
            $quoted = preg_quote($candidate, '/');
            if (preg_match("/^{$quoted} [^=]/", $rhsString) === 1) {
                $operator = $candidate;
                $value = (string) preg_replace("/^{$quoted}[ ]*/", '', $value);
            }
        }
        $operator = self::ALIASES[$operator] ?? $operator;

        if ($operator === 'custom') {
            return null;
        }

        $operator = match ($operator) {
            'equals' => '==',
            'not' => '!=',
            'contains' => 'includes',
            default => $operator,
        };

        $lhs = self::prepareLhs(self::fieldValue($field, $scopes), $operator);
        $rhs = self::prepareRhs($value, $operator);

        if ($operator === 'contains_any') {
            return self::passesContainsAny($lhs, $rhs);
        }

        if ($operator === 'includes') {
            if (is_array($lhs)) {
                return in_array($rhs, $lhs, true);
            }

            return is_string($lhs) && is_string($rhs) && str_contains($lhs, $rhs);
        }

        if ($operator === 'includes_any') {
            $rhsList = is_array($rhs) ? array_map(self::stringify(...), $rhs) : [];
            if (is_array($lhs)) {
                return array_intersect(array_map(self::stringify(...), $lhs), $rhsList) !== [];
            }

            return in_array($lhs, $rhsList, true);
        }

        if ($rhs === 'empty') {
            $lhs = self::isEmpty($lhs);
            $rhs = true;
        }

        if (is_array($lhs)) {
            return false;
        }

        return match ($operator) {
            '==' => self::looseEquals($lhs, $rhs),
            '!=' => ! self::looseEquals($lhs, $rhs),
            '===' => self::strictEquals($lhs, $rhs),
            '!==' => ! self::strictEquals($lhs, $rhs),
            '>', '>=', '<', '<=' => self::compareNumbers($lhs, $rhs, $operator),
            default => null,
        };
    }

    private static function normalizeConditionString(mixed $condition): string
    {
        if ($condition === null) {
            return 'null';
        }

        if ($condition === '') {
            return 'empty';
        }

        if (is_bool($condition)) {
            return $condition ? 'true' : 'false';
        }

        return is_scalar($condition) ? (string) $condition : '';
    }

    /**
     * @param  list<array<mixed>>  $scopes
     */
    private static function fieldValue(string $field, array $scopes): mixed
    {
        if ($scopes === []) {
            return null;
        }

        if (preg_match('/^\$?root\./', $field) === 1) {
            return data_get($scopes[0], (string) preg_replace('/^\$?root\./', '', $field));
        }

        $depth = count($scopes) - 1;
        while (str_starts_with($field, '$parent.')) {
            $field = substr($field, strlen('$parent.'));
            $depth = max(0, $depth - 1);
        }

        return data_get($scopes[$depth], $field);
    }

    private static function prepareLhs(mixed $lhs, string $operator): mixed
    {
        if (in_array($operator, self::NUMERIC_OPERATORS, true)) {
            return self::toNumber($lhs);
        }

        if (($operator === 'includes' || $operator === 'includes_any') && ! is_array($lhs)) {
            return self::isTruthy($lhs) ? self::stringify($lhs) : '';
        }

        if (is_string($lhs)) {
            $lhs = trim($lhs);

            return $lhs === '' ? null : $lhs;
        }

        return $lhs;
    }

    private static function prepareRhs(string $rhs, string $operator): mixed
    {
        switch ($rhs) {
            case 'null':
                return null;
            case 'true':
                return true;
            case 'false':
                return false;
        }

        if (in_array($operator, self::NUMERIC_OPERATORS, true)) {
            return self::toNumber($rhs);
        }

        if ($rhs === 'empty' || $operator === 'includes') {
            return $rhs;
        }

        if ($operator === 'contains_any' || $operator === 'includes_any') {
            return array_map('trim', explode(',', $rhs));
        }

        return trim($rhs);
    }

    private static function passesContainsAny(mixed $lhs, mixed $rhs): bool
    {
        $rhsList = is_array($rhs) ? array_map(self::stringify(...), $rhs) : [];

        if (is_array($lhs)) {
            return array_intersect(array_map(self::stringify(...), $lhs), $rhsList) !== [];
        }

        $haystack = $lhs === null ? '' : self::stringify($lhs);

        foreach ($rhsList as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** JavaScript's `==` for the scalar operands a condition can produce. */
    private static function looseEquals(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        if (is_bool($a)) {
            $a = $a ? 1 : 0;
        }

        if (is_bool($b)) {
            $b = $b ? 1 : 0;
        }

        if (is_string($a) && is_string($b)) {
            return $a === $b;
        }

        if ((is_int($a) || is_float($a) || is_string($a)) && (is_int($b) || is_float($b) || is_string($b))) {
            $na = self::toNumber($a);
            $nb = self::toNumber($b);

            return $na !== null && $nb !== null && $na === $nb;
        }

        return false;
    }

    private static function strictEquals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    private static function compareNumbers(mixed $lhs, mixed $rhs, string $operator): bool
    {
        if (! is_float($lhs) || ! is_float($rhs)) {
            return false; // NaN on either side: every comparison is false
        }

        return match ($operator) {
            '>' => $lhs > $rhs,
            '>=' => $lhs >= $rhs,
            '<' => $lhs < $rhs,
            '<=' => $lhs <= $rhs,
            default => false,
        };
    }

    /** JavaScript's Number(), with null standing in for NaN. */
    private static function toNumber(mixed $value): ?float
    {
        if ($value === null) {
            return 0.0;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return 0.0;
            }

            return is_numeric($trimmed) ? (float) $trimmed : null;
        }

        return null;
    }

    private static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value)) {
            return $value === [];
        }

        if (is_string($value)) {
            return $value === '';
        }

        return false;
    }

    private static function isTruthy(mixed $value): bool
    {
        return ! ($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0);
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
