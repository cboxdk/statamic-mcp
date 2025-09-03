<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ScanBlueprintsTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic Antlers Linter')]
#[IsReadOnly]
class AntlersValidateTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.development.antlers-validate';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Lint Antlers template code against blueprint schemas and detect common issues';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('template')
            ->description('Antlers template code to validate')
            ->required()
            ->string('blueprint')
            ->description('Blueprint handle to validate against')
            ->optional()
            ->string('context')
            ->description('Template context (entry, collection, taxonomy)')
            ->optional()
            ->boolean('strict_mode')
            ->description('Enable strict validation (more pedantic)')
            ->optional()
            ->boolean('performance_analysis')
            ->description('Include performance and edge case analysis')
            ->optional();
    }

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $template = $arguments['template'];
        $blueprintHandle = $arguments['blueprint'] ?? null;
        $context = $arguments['context'] ?? 'entry';
        $strictMode = $arguments['strict_mode'] ?? false;
        $performanceAnalysis = $arguments['performance_analysis'] ?? true;

        // Get blueprint data if blueprint handle is provided
        $blueprint = null;
        if ($blueprintHandle) {
            $scanTool = new ScanBlueprintsTool;
            $scanResult = $scanTool->execute([]);
            $scanData = $scanResult;

            if (isset($scanData['blueprints'][$blueprintHandle])) {
                $blueprint = $scanData['blueprints'][$blueprintHandle];
            }
        }

        if ($blueprintHandle && ! $blueprint) {
            return $this->createErrorResponse("Blueprint '{$blueprintHandle}' not found", [
                'available_blueprints' => array_keys($scanData['blueprints'] ?? []),
                'errors' => [
                    [
                        'code' => 'blueprint_not_found',
                        'message' => "Blueprint '{$blueprintHandle}' not found",
                        'line' => null,
                        'column' => null,
                    ],
                ],
            ])->toArray();
        }

        // Parse and validate the template
        $validator = new AntlersTemplateValidator($blueprint, $context, $strictMode);
        $result = $validator->validate($template);

        // Add performance and edge case analysis if requested
        if ($performanceAnalysis) {
            $analyzer = new OptimizedTemplateAnalyzer;
            $performanceResult = $analyzer->analyzePerformance($template, 'antlers');
            $edgeCases = $analyzer->detectEdgeCases($template, 'antlers');

            $result['performance_analysis'] = $performanceResult;
            $result['edge_cases'] = $edgeCases;
        }

        return $result;
    }
}

/**
 * Antlers Template Validator
 */
class AntlersTemplateValidator
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $blueprint;

    private string $context;

    private bool $strictMode;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $errors = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $warnings = [];

    private int $currentLine = 1;

    private int $currentColumn = 1;

    /**
     * @param  ?array<string, mixed>  $blueprint
     */
    public function __construct(?array $blueprint, string $context, bool $strictMode = false)
    {
        $this->blueprint = $blueprint;
        $this->context = $context;
        $this->strictMode = $strictMode;
    }

    /**
     * Validate the template.
     */
    /**
     * @return array<string, mixed>
     */
    public function validate(string $template): array
    {
        $this->errors = [];
        $this->warnings = [];
        $this->currentLine = 1;
        $this->currentColumn = 1;

        // Parse Antlers tags
        $tags = $this->parseAntlersTags($template);

        // Validate each tag
        foreach ($tags as $tag) {
            $this->validateTag($tag);
        }

        // Check for common template issues
        $this->checkCommonIssues($template);

        return [
            'ok' => empty($this->errors),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'suggestions' => $this->generateSuggestions(),
            'stats' => [
                'total_tags' => count($tags),
                'error_count' => count($this->errors),
                'warning_count' => count($this->warnings),
            ],
        ];
    }

    /**
     * Parse Antlers tags from template.
     *
     * @return array<string, mixed>
     */
    private function parseAntlersTags(string $template): array
    {
        $tags = [];
        $lines = explode("\n", $template);

        foreach ($lines as $lineNumber => $line) {
            $this->currentLine = $lineNumber + 1;

            // Match Antlers tags: {{ ... }}
            if (preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $line, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $match) {
                    $tagContent = trim($match[0]);
                    $position = $match[1];

                    $this->currentColumn = $position + 1;

                    $tag = $this->parseTagContent($tagContent);
                    $tag['line'] = $this->currentLine;
                    $tag['column'] = $this->currentColumn;
                    $tag['raw'] = '{{ ' . $tagContent . ' }}';

                    $tags[] = $tag;
                }
            }
        }

        /** @var array<string, mixed> */
        return collect($tags)->mapWithKeys(fn ($item, $index) => [(string) $index => $item])->all();
    }

    /**
     * Parse individual tag content.
     *
     * @return array<string, mixed>
     */
    private function parseTagContent(string $content): array
    {
        $tag = [
            'type' => 'unknown',
            'name' => '',
            'params' => [],
            'modifiers' => [],
            'is_closing' => false,
            'is_self_closing' => false,
            'condition' => null, // For conditionals
        ];

        // Check for closing tag
        if (str_starts_with($content, '/')) {
            $tag['is_closing'] = true;
            $content = substr($content, 1);
        }

        // Split on pipes for modifiers
        $parts = explode('|', $content);
        $mainPart = trim($parts[0]);

        if (count($parts) > 1) {
            $tag['modifiers'] = array_map('trim', array_slice($parts, 1));
        }

        // Parse main part
        if (str_contains($mainPart, ':')) {
            // Tag with namespace (nav:main, collection:blog)
            $tagParts = explode(':', $mainPart, 2);
            $tag['type'] = 'namespaced_tag';
            $tag['namespace'] = $tagParts[0];
            $tag['name'] = $tagParts[1];

            // Handle namespaced tag parameters
            if (str_contains($tag['name'], ' ')) {
                $nameParts = explode(' ', $tag['name']);
                $tag['name'] = $nameParts[0];
                $tag['params'] = $this->parseParameters(implode(' ', array_slice($nameParts, 1)));
            }
        } elseif (str_contains($mainPart, ' ')) {
            // Tag with parameters or condition
            $parts = explode(' ', $mainPart);
            $tag['name'] = $parts[0];
            $tag['type'] = $this->determineTagType($tag['name']);

            $remainingContent = implode(' ', array_slice($parts, 1));

            // For conditionals, the rest is the condition
            if ($tag['type'] === 'conditional') {
                $tag['condition'] = $remainingContent;
            } else {
                $tag['params'] = $this->parseParameters($remainingContent);
            }
        } else {
            // Simple field, tag, or conditional
            $tag['name'] = $mainPart;
            $tag['type'] = $this->determineTagType($tag['name']);
        }

        return $tag;
    }

    /**
     * Determine the type of tag.
     */
    private function determineTagType(string $name): string
    {
        // Control structures
        if (in_array($name, ['if', 'unless', 'elseif', 'else', 'endif', 'endunless'])) {
            return 'conditional';
        }

        if (in_array($name, ['foreach', 'endforeach'])) {
            return 'loop';
        }

        // Built-in variables
        if (in_array($name, ['site', 'config', 'env', 'current_date', 'now'])) {
            return 'global_variable';
        }

        // Check if it's a field from the blueprint
        if ($this->blueprint && isset($this->blueprint['fields'][$name])) {
            return 'blueprint_field';
        }

        // Check if it's a context variable
        if ($this->isContextVariable($name)) {
            return 'context_variable';
        }

        return 'unknown';
    }

    /**
     * Check if a variable is available in the current context.
     */
    private function isContextVariable(string $name): bool
    {
        // Global variables available everywhere
        $globalVars = [
            'site', 'config', 'env', 'current_date', 'now', 'today', 'yesterday', 'tomorrow',
            'csrf_token', 'csrf_field', 'get', 'post', 'get_post', 'old', 'errors',
            'user', 'logged_in', 'logged_out', 'is_logged_in', 'is_logged_out',
            'segment_1', 'segment_2', 'segment_3', 'last_segment', 'current_url', 'current_uri',
            'homepage', 'is_homepage', 'locale', 'locales', 'site_locale',
        ];

        if (in_array($name, $globalVars)) {
            return true;
        }

        $contextVars = [
            'entry' => [
                'id', 'slug', 'url', 'permalink', 'title', 'collection', 'collection_handle',
                'published', 'status', 'date', 'last_modified', 'created_at', 'updated_at',
                'author', 'author_id', 'edit_url', 'api_url', 'is_entry', 'blueprint',
                'locale', 'localized_slug', 'parent', 'children', 'has_children',
                'is_root', 'depth', 'order', 'mount', 'is_page',
            ],
            'collection' => [
                'handle', 'title', 'entries', 'count', 'url', 'api_url',
                'edit_url', 'create_url', 'blueprint', 'mount', 'route',
            ],
            'taxonomy' => [
                'handle', 'title', 'slug', 'url', 'permalink', 'entries', 'count',
                'api_url', 'edit_url', 'blueprint', 'collection', 'collections',
                'id', 'is_term', 'locale', 'localized_slug',
            ],
        ];

        return in_array($name, $contextVars[$this->context] ?? []);
    }

    /**
     * Parse tag parameters.
     *
     * @return array<string, mixed>
     */
    private function parseParameters(string $params): array
    {
        $parameters = [];
        $params = trim($params);

        if (empty($params)) {
            return $parameters;
        }

        // Handle quoted parameters: param="value" or param='value'
        if (preg_match_all('/(\w+)=(["\'])([^"\']*)\2/', $params, $matches)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $parameters[$matches[1][$i]] = $matches[3][$i];
            }
        }

        // Handle unquoted parameters: param=value
        if (preg_match_all('/(\w+)=([^\s"\']+)/', $params, $matches)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                if (! isset($parameters[$matches[1][$i]])) { // Don't overwrite quoted values
                    $parameters[$matches[1][$i]] = $matches[2][$i];
                }
            }
        }

        // Handle boolean/flag parameters: just "param"
        $words = explode(' ', $params);
        foreach ($words as $word) {
            $word = trim($word);
            if (! empty($word) && ! str_contains($word, '=') && ! isset($parameters[$word])) {
                $parameters[$word] = true; // Flag parameter
            }
        }

        return $parameters;
    }

    /**
     * Validate individual tag.
     *
     * @param  array<string, mixed>  $tag
     */
    private function validateTag(array $tag): void
    {
        switch ($tag['type']) {
            case 'blueprint_field':
                $this->validateBlueprintField($tag);
                break;
            case 'namespaced_tag':
                $this->validateNamespacedTag($tag);
                break;
            case 'conditional':
                $this->validateConditional($tag);
                break;
            case 'loop':
                $this->validateLoop($tag);
                break;
            case 'unknown':
                $this->validateUnknownTag($tag);
                break;
        }

        // Validate modifiers
        $this->validateModifiers($tag);
    }

    /**
     * Validate blueprint field usage.
     *
     * @param  array<string, mixed>  $tag
     */
    private function validateBlueprintField(array $tag): void
    {
        $fieldName = $tag['name'];
        $field = $this->blueprint['fields'][$fieldName] ?? [];

        // Check required fields
        if (($field['required'] ?? false) && $this->strictMode) {
            $this->addWarning(
                'required_field_usage',
                "Field '{$fieldName}' is required - ensure it has a value",
                $tag
            );
        }

        // Type-specific validation
        $this->validateFieldType($tag, $field);
    }

    /**
     * Validate field type specific usage.
     *
     * @param  array<string, mixed>  $tag
     * @param  array<string, mixed>  $field
     */
    private function validateFieldType(array $tag, array $field): void
    {
        $fieldType = $field['type'] ?? 'text';
        $fieldName = $tag['name'];

        switch ($fieldType) {
            case 'assets':
                if (! empty($tag['modifiers'])) {
                    $invalidModifiers = array_diff($tag['modifiers'], ['glide', 'resize', 'crop']);
                    if (! empty($invalidModifiers) && $this->strictMode) {
                        $this->addWarning(
                            'invalid_asset_modifier',
                            "Field '{$fieldName}' is an asset field. Consider using glide, resize, or crop modifiers",
                            $tag
                        );
                    }
                }
                break;

            case 'date':
                if (! in_array('format', array_keys($tag['params'])) && $this->strictMode) {
                    $this->addWarning(
                        'missing_date_format',
                        "Date field '{$fieldName}' should specify a format parameter",
                        $tag
                    );
                }
                break;

            case 'entries':
            case 'taxonomy':
                if (! $tag['is_closing'] && ! str_contains($tag['raw'], '{{/' . $fieldName . '}}')) {
                    $this->addError(
                        'missing_closing_tag',
                        "Relationship field '{$fieldName}' requires a closing tag: {{/{$fieldName}}}",
                        $tag
                    );
                }
                break;

            case 'bard':
            case 'replicator':
                if (! $tag['is_closing'] && $this->strictMode) {
                    $this->addWarning(
                        'complex_field_usage',
                        "Complex field '{$fieldName}' may require conditional logic for sets",
                        $tag
                    );
                }
                break;
        }
    }

    /**
     * Validate namespaced tags.
     *
     * @param  array<string, mixed>  $tag
     */
    private function validateNamespacedTag(array $tag): void
    {
        $namespace = $tag['namespace'];
        $name = $tag['name'];

        $validNamespaces = [
            'collection', 'taxonomy', 'nav', 'form', 'glide', 'partial',
            'section', 'yield', 'site', 'config', 'env',
        ];

        if (! in_array($namespace, $validNamespaces)) {
            $this->addError(
                'unknown_namespace',
                "Unknown tag namespace '{$namespace}'. Valid namespaces: " . implode(', ', $validNamespaces),
                $tag
            );
        }

        // Namespace-specific validation
        switch ($namespace) {
            case 'collection':
                $this->validateCollectionTag($tag);
                break;
            case 'glide':
                $this->validateGlideTag($tag);
                break;
        }
    }

    /**
     * Validate collection tags.
     *
     * @param  array<string, mixed>  $tag
     */
    private function validateCollectionTag(array $tag): void
    {
        if (! $tag['is_closing'] && empty($tag['params'])) {
            $this->addWarning(
                'collection_without_params',
                "Collection tag '{$tag['name']}' might benefit from parameters like limit or sort",
                $tag
            );
        }
    }

    /**
     * Validate glide tags.
     *
     * @param  array<string, mixed>  $tag
     */
    private function validateGlideTag(array $tag): void
    {
        $recommendedParams = ['width', 'height', 'quality', 'fit'];
        $hasRecommended = ! empty(array_intersect(array_keys($tag['params']), $recommendedParams));

        if (! $hasRecommended && $this->strictMode) {
            $this->addWarning(
                'glide_without_params',
                'Glide tag should specify dimensions or quality parameters',
                $tag
            );
        }
    }

    /**
     * Validate conditionals.
     *
     * @param  array<string, mixed>  $tag
     */
    private function validateConditional(array $tag): void
    {
        // Basic conditional validation
        if ($tag['name'] === 'if' && empty($tag['condition']) && empty($tag['params'])) {
            $this->addError(
                'empty_conditional',
                "Conditional 'if' statements require a condition",
                $tag
            );
        }

        // Validate the condition if present
        if (! empty($tag['condition'])) {
            $this->validateConditionExpression($tag['condition'], $tag);
        }
    }

    /**
     * Validate condition expressions in conditionals.
     *
     * @param  array<string, mixed>  $tag
     */
    private function validateConditionExpression(string $condition, array $tag): void
    {
        // Remove leading/trailing whitespace
        $condition = trim($condition);

        // Simple field existence check: {{ if field_name }}
        if (! str_contains($condition, ' ') && ! str_contains($condition, '=') && ! str_contains($condition, '!')) {
            // This is a simple field check, validate the field exists
            if ($this->blueprint && ! isset($this->blueprint['fields'][$condition])) {
                // Check if it's a context variable
                if (! $this->isContextVariable($condition)) {
                    $this->addWarning(
                        'unknown_condition_field',
                        "Field '{$condition}' used in condition but not found in blueprint",
                        $tag
                    );
                }
            }
        }

        // Advanced condition parsing could be implemented for complex conditional logic
        // for expressions like "field == 'value'" or "field != null"
    }

    /**
     * Validate loops.
     *
     * @param  array<string, mixed>  $tag
     */
    private function validateLoop(array $tag): void
    {
        // Basic loop validation
        if ($tag['name'] === 'foreach' && empty($tag['params'])) {
            $this->addError(
                'empty_loop',
                'Foreach loops require a variable to iterate over',
                $tag
            );
        }
    }

    /**
     * Validate unknown tags.
     *
     * @param  array<string, mixed>  $tag
     */
    private function validateUnknownTag(array $tag): void
    {
        $fieldNames = array_keys($this->blueprint['fields'] ?? []);
        /** @var array<int, string> $stringFieldNames */
        $stringFieldNames = array_filter($fieldNames, 'is_string');
        $suggestions = $this->findSimilarFields($tag['name'], $stringFieldNames);

        $message = "Unknown field or tag '{$tag['name']}'";
        if (! empty($suggestions)) {
            $message .= '. Did you mean: ' . implode(', ', $suggestions) . '?';
        }

        $this->addError('unknown_field', $message, $tag);
    }

    /**
     * Validate modifiers.
     *
     * @param  array<string, mixed>  $tag
     */
    private function validateModifiers(array $tag): void
    {
        $validModifiers = [
            'upper', 'lower', 'title', 'sentence', 'slug', 'studly', 'camel',
            'length', 'word_count', 'read_time', 'strip_tags', 'markdown',
            'textile', 'smartypants', 'widont', 'format', 'relative',
            'iso_format', 'modify', 'add', 'subtract', 'multiply', 'divide',
            'round', 'ceil', 'floor', 'abs', 'sort', 'reverse', 'shuffle',
            'limit', 'offset', 'unique', 'pluck', 'where', 'where_not',
            'group_by', 'collapse', 'flatten', 'contains', 'starts_with',
            'ends_with', 'matches', 'split', 'join', 'replace', 'regex_replace',
        ];

        foreach ($tag['modifiers'] as $modifier) {
            if (! in_array($modifier, $validModifiers) && $this->strictMode) {
                $this->addWarning(
                    'unknown_modifier',
                    "Unknown modifier '{$modifier}'. Check spelling or documentation",
                    $tag
                );
            }
        }
    }

    /**
     * Check for common template issues.
     */
    private function checkCommonIssues(string $template): void
    {
        // Check for unclosed tags
        $this->checkUnclosedTags($template);

        // Check for missing alt text on images
        $this->checkImageAltText($template);

        // Check for hardcoded URLs
        $this->checkHardcodedUrls($template);
    }

    /**
     * Check for unclosed tags.
     */
    private function checkUnclosedTags(string $template): void
    {
        $openTags = [];
        $lines = explode("\n", $template);

        foreach ($lines as $lineNumber => $line) {
            // Check for malformed tags (opening braces without closing braces)
            if (preg_match('/\{\{[^}]*$/', $line)) {
                $this->errors[] = [
                    'type' => 'syntax_error',
                    'code' => 'unclosed_tag',
                    'message' => 'Unclosed Antlers tag detected - missing closing }}',
                    'line' => $lineNumber + 1,
                    'column' => strpos($line, '{{') + 1,
                    'severity' => 'error',
                ];
            }

            if (preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $line, $matches)) {
                foreach ($matches[1] as $tagContent) {
                    $tagContent = trim($tagContent);

                    if (str_starts_with($tagContent, '/')) {
                        // Closing tag
                        $tagName = substr($tagContent, 1);
                        if (! empty($openTags) && end($openTags)['name'] === $tagName) {
                            array_pop($openTags);
                        }
                    } elseif (preg_match('/^(collection:|taxonomy:|nav:|entries|taxonomy|users|assets|bard|replicator|if|unless|foreach)/', $tagContent)) {
                        // Opening tag that requires closing
                        $tagName = explode(':', $tagContent)[0];
                        $tagName = explode(' ', $tagName)[0];
                        $openTags[] = ['name' => $tagName, 'line' => $lineNumber + 1];
                    }
                }
            }
        }

        foreach ($openTags as $openTag) {
            $this->addError(
                'unclosed_tag',
                "Unclosed tag '{$openTag['name']}' - missing closing tag",
                ['line' => $openTag['line'], 'column' => 1]
            );
        }
    }

    /**
     * Check for missing alt text on images.
     */
    private function checkImageAltText(string $template): void
    {
        if (preg_match('/<img[^>]*(?!.*alt=)[^>]*>/', $template) && $this->strictMode) {
            $this->addWarning(
                'missing_alt_text',
                'Images should have alt text for accessibility',
                ['line' => null, 'column' => null]
            );
        }
    }

    /**
     * Check for hardcoded URLs.
     */
    private function checkHardcodedUrls(string $template): void
    {
        if (preg_match('/https?:\/\/[^\s"\']+/', $template) && $this->strictMode) {
            $this->addWarning(
                'hardcoded_url',
                'Consider using relative URLs or site configuration instead of hardcoded URLs',
                ['line' => null, 'column' => null]
            );
        }
    }

    /**
     * Find similar field names.
     *
     * @param  array<int, string>  $haystack
     *
     * @return array<int, string>
     */
    private function findSimilarFields(string $needle, array $haystack): array
    {
        $suggestions = [];

        foreach ($haystack as $field) {
            $distance = levenshtein(strtolower($needle), strtolower($field));
            if ($distance <= 2) {
                $suggestions[] = $field;
            }
        }

        return array_slice($suggestions, 0, 3);
    }

    /**
     * Generate suggestions for improvement.
     *
     * @return array<string, mixed>
     */
    private function generateSuggestions(): array
    {
        $suggestions = [];

        // Suggest using available fields
        if (! empty($this->blueprint['fields'])) {
            $suggestions[] = [
                'type' => 'available_fields',
                'message' => 'Available fields in this blueprint',
                'fields' => array_keys($this->blueprint['fields']),
            ];
        }

        // Suggest common patterns
        $suggestions[] = [
            'type' => 'common_patterns',
            'message' => 'Common Antlers patterns',
            'patterns' => [
                '{{ if field }}...{{ /if }}' => 'Conditional display',
                '{{ entries }}{{ title }}{{ /entries }}' => 'Loop through entries',
                '{{ field | limit:3 }}' => 'Using modifiers',
                '{{ glide:image width="300" }}' => 'Image manipulation',
            ],
        ];

        /** @var array<string, mixed> */
        return collect($suggestions)->mapWithKeys(fn ($item, $key) => [(string) $key => $item])->all();
    }

    /**
     * Add an error.
     *
     * @param  array<string, mixed>  $tag
     */
    private function addError(string $code, string $message, array $tag): void
    {
        $this->errors[] = [
            'code' => $code,
            'message' => $message,
            'line' => $tag['line'] ?? null,
            'column' => $tag['column'] ?? null,
            'severity' => 'error',
        ];
    }

    /**
     * Add a warning.
     *
     * @param  array<string, mixed>  $tag
     */
    private function addWarning(string $code, string $message, array $tag): void
    {
        $this->warnings[] = [
            'code' => $code,
            'message' => $message,
            'line' => $tag['line'] ?? null,
            'column' => $tag['column'] ?? null,
            'severity' => 'warning',
        ];
    }
}
