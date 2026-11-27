<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Stored Forms 2 page rules, shared by definition writes and Forms Pro reads. */
final class FormPageRules
{
    /** @param array<array-key, mixed> $definition */
    public static function validate(array $definition): void
    {
        $pages = is_array($definition['pages'] ?? null) ? $definition['pages'] : [];
        Validator::make(['pages' => $pages], [
            'pages.*.rules' => ['sometimes', 'array', 'list'],
            'pages.*.rules.*' => ['array:conditions,destination'],
            'pages.*.rules.*.destination' => ['required', 'string', Rule::in(array_column($pages, 'id'))],
            'pages.*.rules.*.conditions' => ['required', 'array', 'list'],
            'pages.*.rules.*.conditions.*' => ['array:join,field,operator,value'],
            'pages.*.rules.*.conditions.*.field' => ['required', 'string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/D'],
            'pages.*.rules.*.conditions.*.join' => ['sometimes', 'in:and,or'],
            'pages.*.rules.*.conditions.*.operator' => ['sometimes', Rule::in([
                'is', 'equals', 'isnt', 'not', 'contains', 'includes', 'contains_any', 'includes_any', '==', '!=', '===', '!==', '>', '>=', '<', '<=',
            ])],
            'pages.*.rules.*.conditions.*.value' => ['present', 'nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('A page condition value must be a scalar or null.');
                }
            }],
        ])->validate();
    }

    /** @param array<array-key, mixed> $rule
     * @param  array<int, string>  $denied
     */
    public static function referencesProtectedField(array $rule, array $denied): bool
    {
        foreach (is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [] as $condition) {
            if (is_array($condition) && is_string($condition['field'] ?? null)
                && array_intersect(explode('.', $condition['field']), $denied) !== []) {
                return true;
            }
        }

        return false;
    }
}
