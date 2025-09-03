<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Strategies;

class TagValidationStrategy implements ValidationStrategy
{
    /** @var list<string> */
    private array $knownTags = [
        'collection', 'taxonomy', 'nav', 'form', 'glide', 'partial', 'section', 'yield',
        'if', 'elseif', 'else', 'unless', 'foreach', 'for', 'while', 'user',
        'users', 'entries', 'assets', 'terms', 'redirect', 'layout',
    ];

    /** @var list<string> */
    private array $knownModifiers = [
        'format', 'markdown', 'strip_tags', 'truncate', 'upper', 'lower', 'title',
        'slugify', 'relative', 'count', 'length', 'first', 'last', 'limit',
        'offset', 'sort_by', 'group_by', 'where', 'pluck', 'unique', 'reverse',
        'shuffle', 'sum', 'average', 'min', 'max', 'join', 'split', 'replace',
        'contains', 'starts_with', 'ends_with', 'trim', 'ltrim', 'rtrim',
        'urlencode', 'urldecode', 'json', 'to_json', 'from_json', 'base64_encode',
        'base64_decode', 'md5', 'sha1', 'sha256', 'is_past', 'is_future',
        'is_today', 'is_yesterday', 'is_tomorrow', 'add_query_param', 'remove_query_param',
    ];

    /**
     * Validate tag usage.
     */
    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $context
     *
     * @return list<array<string, mixed>>
     */
    public function validate(array $content, array $context = []): array
    {
        $errors = [];

        if (! isset($content['tags'])) {
            return $errors;
        }

        foreach ($content['tags'] as $tag) {
            $errors = array_merge($errors, $this->validateTag($tag, $context));
        }

        return $errors;
    }

    /**
     * Get the strategy name.
     */
    public function getName(): string
    {
        return 'tag_validation';
    }

    /**
     * Check if this strategy applies.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $context
     */
    public function appliesTo(array $content, array $context = []): bool
    {
        return isset($content['tags']) && is_array($content['tags']);
    }

    /**
     * Validate individual tag.
     */
    /**
     * @param  array<string, mixed>  $tag
     * @param  array<string, mixed>  $context
     *
     * @return list<array<string, mixed>>
     */
    private function validateTag(array $tag, array $context): array
    {
        $errors = [];

        // Validate tag structure
        if (! isset($tag['name'])) {
            $errors[] = [
                'type' => 'structure_error',
                'code' => 'missing_tag_name',
                'message' => 'Tag is missing name property',
                'line' => $tag['line'] ?? null,
                'column' => $tag['column'] ?? null,
                'severity' => 'error',
            ];

            return $errors;
        }

        // Validate known tags
        if (! $this->isKnownTag($tag['name'])) {
            // Check if it might be a blueprint field
            if (isset($context['blueprint']['fields'][$tag['name']])) {
                // This is a blueprint field, validate field usage
                $errors = array_merge($errors, $this->validateBlueprintField($tag, $context));
            } else {
                $errors[] = [
                    'type' => 'unknown_tag',
                    'code' => 'unknown_tag',
                    'message' => "Unknown tag or variable: '{$tag['name']}'",
                    'line' => $tag['line'] ?? null,
                    'column' => $tag['column'] ?? null,
                    'severity' => 'warning',
                    'suggestions' => $this->getSimilarTags($tag['name']),
                ];
            }
        } else {
            // Validate known tag parameters
            $errors = array_merge($errors, $this->validateTagParameters($tag, $context));
        }

        // Validate modifiers
        if (isset($tag['modifiers'])) {
            $errors = array_merge($errors, $this->validateModifiers($tag, $context));
        }

        return $errors;
    }

    /**
     * Check if tag name is known.
     */
    private function isKnownTag(string $name): bool
    {
        return in_array($name, $this->knownTags) || str_contains($name, ':');
    }

    /**
     * Validate blueprint field usage.
     */
    /**
     * @param  array<string, mixed>  $tag
     * @param  array<string, mixed>  $context
     *
     * @return list<array<string, mixed>>
     */
    private function validateBlueprintField(array $tag, array $context): array
    {
        $errors = [];
        $fieldName = $tag['name'];
        $field = $context['blueprint']['fields'][$fieldName];

        // Check field type compatibility
        $fieldType = $field['type'] ?? 'text';

        if (isset($tag['modifiers'])) {
            foreach ($tag['modifiers'] as $modifier) {
                if (! $this->isModifierCompatibleWithField($modifier, $fieldType)) {
                    $errors[] = [
                        'type' => 'compatibility_error',
                        'code' => 'incompatible_modifier',
                        'message' => "Modifier '{$modifier}' is not compatible with field type '{$fieldType}'",
                        'line' => $tag['line'] ?? null,
                        'column' => $tag['column'] ?? null,
                        'severity' => 'warning',
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Validate tag parameters.
     */
    /**
     * @param  array<string, mixed>  $tag
     * @param  array<string, mixed>  $context
     *
     * @return list<array<string, mixed>>
     */
    private function validateTagParameters(array $tag, array $context): array
    {
        $errors = [];
        $tagName = $tag['name'];

        // Define required/optional parameters for known tags
        $tagParameters = [
            'collection' => [
                'required' => [],
                'optional' => ['from', 'limit', 'offset', 'sort', 'filter', 'paginate'],
            ],
            'taxonomy' => [
                'required' => [],
                'optional' => ['from', 'limit', 'sort'],
            ],
            'glide' => [
                'required' => [],
                'optional' => ['width', 'height', 'quality', 'format', 'fit', 'crop'],
            ],
        ];

        if (isset($tagParameters[$tagName])) {
            $params = $tagParameters[$tagName];
            $tagParams = $tag['params'] ?? [];

            // Check required parameters
            foreach ($params['required'] as $required) {
                if (! isset($tagParams[$required])) {
                    $errors[] = [
                        'type' => 'missing_parameter',
                        'code' => 'missing_required_parameter',
                        'message' => "Required parameter '{$required}' is missing for tag '{$tagName}'",
                        'line' => $tag['line'] ?? null,
                        'column' => $tag['column'] ?? null,
                        'severity' => 'error',
                    ];
                }
            }

            // Check for unknown parameters
            $allValidParams = array_merge($params['required'], $params['optional']);
            foreach (array_keys($tagParams) as $param) {
                if (! in_array($param, $allValidParams)) {
                    $errors[] = [
                        'type' => 'unknown_parameter',
                        'code' => 'unknown_parameter',
                        'message' => "Unknown parameter '{$param}' for tag '{$tagName}'",
                        'line' => $tag['line'] ?? null,
                        'column' => $tag['column'] ?? null,
                        'severity' => 'warning',
                        'suggestions' => $this->getSimilarParameters($param, $allValidParams),
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Validate modifiers.
     */
    /**
     * @param  array<string, mixed>  $tag
     * @param  array<string, mixed>  $context
     *
     * @return list<array<string, mixed>>
     */
    private function validateModifiers(array $tag, array $context): array
    {
        $errors = [];

        foreach ($tag['modifiers'] as $modifier) {
            if (! in_array($modifier, $this->knownModifiers)) {
                $errors[] = [
                    'type' => 'unknown_modifier',
                    'code' => 'unknown_modifier',
                    'message' => "Unknown modifier: '{$modifier}'",
                    'line' => $tag['line'] ?? null,
                    'column' => $tag['column'] ?? null,
                    'severity' => 'warning',
                    'suggestions' => $this->getSimilarModifiers($modifier),
                ];
            }
        }

        return $errors;
    }

    /**
     * Check modifier compatibility with field type.
     */
    private function isModifierCompatibleWithField(string $modifier, string $fieldType): bool
    {
        $fieldTypeModifiers = [
            'text' => ['upper', 'lower', 'title', 'truncate', 'strip_tags', 'contains', 'starts_with', 'ends_with'],
            'textarea' => ['markdown', 'strip_tags', 'truncate', 'nl2br'],
            'date' => ['format', 'relative', 'is_past', 'is_future', 'is_today'],
            'integer' => ['format', 'sum', 'average', 'min', 'max'],
            'float' => ['format', 'sum', 'average', 'min', 'max'],
            'assets' => ['count', 'first', 'last', 'limit', 'offset'],
            'entries' => ['count', 'first', 'last', 'limit', 'offset', 'sort_by', 'where'],
            'taxonomy' => ['count', 'first', 'last', 'pluck'],
        ];

        return in_array($modifier, $fieldTypeModifiers[$fieldType] ?? []) ||
               in_array($modifier, ['count', 'length', 'first', 'last']); // Universal modifiers
    }

    /**
     * Get similar tags for suggestions.
     */
    /**
     * @return list<string>
     */
    private function getSimilarTags(string $tag): array
    {
        $similar = [];

        foreach ($this->knownTags as $knownTag) {
            if (levenshtein($tag, $knownTag) <= 2) {
                $similar[] = $knownTag;
            }
        }

        return array_slice($similar, 0, 3);
    }

    /**
     * Get similar modifiers for suggestions.
     */
    /**
     * @return list<string>
     */
    private function getSimilarModifiers(string $modifier): array
    {
        $similar = [];

        foreach ($this->knownModifiers as $knownModifier) {
            if (levenshtein($modifier, $knownModifier) <= 2) {
                $similar[] = $knownModifier;
            }
        }

        return array_slice($similar, 0, 3);
    }

    /**
     * Get similar parameters for suggestions.
     */
    /**
     * @param  list<string>  $validParams
     *
     * @return list<string>
     */
    private function getSimilarParameters(string $param, array $validParams): array
    {
        $similar = [];

        foreach ($validParams as $validParam) {
            if (levenshtein($param, $validParam) <= 2) {
                $similar[] = $validParam;
            }
        }

        return array_slice($similar, 0, 3);
    }
}
