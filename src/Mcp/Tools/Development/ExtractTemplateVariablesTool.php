<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Extract Template Variables')]
#[IsReadOnly]
class ExtractTemplateVariablesTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.development.extract-variables';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Extract and analyze variables used in templates for documentation and type generation';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('template_path')
            ->description('Path to template file or directory to analyze')
            ->required()
            ->string('template_type')
            ->description('Template type: antlers, blade, or auto-detect')
            ->optional()
            ->boolean('include_partials')
            ->description('Include variables from referenced partials')
            ->optional()
            ->boolean('detect_types')
            ->description('Attempt to detect variable types from usage patterns')
            ->optional()
            ->boolean('group_by_context')
            ->description('Group variables by their usage context')
            ->optional()
            ->boolean('include_documentation')
            ->description('Generate documentation for discovered variables')
            ->optional()
            ->boolean('suggest_types')
            ->description('Suggest TypeScript/PHP type definitions')
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
        $templatePath = $arguments['template_path'];
        $templateType = $arguments['template_type'] ?? 'auto-detect';
        $includePartials = $arguments['include_partials'] ?? true;
        $detectTypes = $arguments['detect_types'] ?? true;
        $groupByContext = $arguments['group_by_context'] ?? true;
        $includeDocumentation = $arguments['include_documentation'] ?? true;
        $suggestTypes = $arguments['suggest_types'] ?? true;

        try {
            $templates = $this->collectTemplates($templatePath);

            if (empty($templates)) {
                return $this->createErrorResponse('No templates found at the specified path')->toArray();
            }

            $analysis = [
                'templates_analyzed' => count($templates),
                'variables' => [],
                'variable_usage' => [],
                'type_suggestions' => [],
                'documentation' => [],
                'statistics' => [
                    'total_variables' => 0,
                    'typed_variables' => 0,
                    'untyped_variables' => 0,
                    'complex_variables' => 0,
                ],
                'contexts' => [],
            ];

            foreach ($templates as $template) {
                $templateAnalysis = $this->analyzeTemplateVariables(
                    $template,
                    $templateType,
                    $includePartials,
                    $detectTypes,
                    $groupByContext
                );

                // Merge variables
                foreach ($templateAnalysis['variables'] as $varName => $varData) {
                    if (isset($analysis['variables'][$varName])) {
                        $analysis['variables'][$varName] = $this->mergeVariableData(
                            $analysis['variables'][$varName],
                            $varData
                        );
                    } else {
                        $analysis['variables'][$varName] = $varData;
                    }
                }

                // Merge usage data
                $analysis['variable_usage'] = array_merge_recursive(
                    $analysis['variable_usage'],
                    $templateAnalysis['usage']
                );

                // Merge contexts
                if ($groupByContext) {
                    $analysis['contexts'] = array_merge_recursive(
                        $analysis['contexts'],
                        $templateAnalysis['contexts']
                    );
                }
            }

            // Update statistics
            $analysis['statistics']['total_variables'] = count($analysis['variables']);

            foreach ($analysis['variables'] as $variable) {
                if (isset($variable['type']) && $variable['type'] !== 'unknown') {
                    $analysis['statistics']['typed_variables']++;
                } else {
                    $analysis['statistics']['untyped_variables']++;
                }

                if ($variable['complexity'] === 'complex') {
                    $analysis['statistics']['complex_variables']++;
                }
            }

            // Generate type suggestions
            if ($suggestTypes) {
                $analysis['type_suggestions'] = $this->generateTypeSuggestions($analysis['variables']);
            }

            // Generate documentation
            if ($includeDocumentation) {
                $analysis['documentation'] = $this->generateVariableDocumentation($analysis['variables']);
            }

            return [
                'analysis' => $analysis,
                'summary' => [
                    'variable_coverage' => $analysis['statistics']['total_variables'] > 0
                        ? round(($analysis['statistics']['typed_variables'] / $analysis['statistics']['total_variables']) * 100, 1)
                        : 100,
                    'most_used_variables' => $this->getMostUsedVariables($analysis['variable_usage']),
                    'complex_variable_count' => $analysis['statistics']['complex_variables'],
                    'suggested_types_count' => count($analysis['type_suggestions']),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to extract template variables: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Collect templates from path.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function collectTemplates(string $templatePath): array
    {
        $templates = [];
        $basePath = resource_path('views');

        if (! str_starts_with($templatePath, '/')) {
            $fullPath = $basePath . '/' . ltrim($templatePath, '/');
        } else {
            $fullPath = $templatePath;
        }

        if (is_file($fullPath)) {
            $templates[] = [
                'path' => $fullPath,
                'relative_path' => str_replace($basePath . '/', '', $fullPath),
                'content' => file_get_contents($fullPath),
            ];
        } elseif (is_dir($fullPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), ['antlers.html', 'blade.php', 'html', 'php'])) {
                    $templates[] = [
                        'path' => $file->getPathname(),
                        'relative_path' => str_replace($basePath . '/', '', $file->getPathname()),
                        'content' => file_get_contents($file->getPathname()),
                    ];
                }
            }
        }

        return $templates;
    }

    /**
     * Analyze variables in a single template.
     *
     * @param  array<string, mixed>  $template
     *
     * @return array<string, mixed>
     */
    private function analyzeTemplateVariables(
        array $template,
        string $templateType,
        bool $includePartials,
        bool $detectTypes,
        bool $groupByContext
    ): array {
        $content = $template['content'];
        $templateFile = $template['relative_path'];

        // Auto-detect template type
        if ($templateType === 'auto-detect') {
            $templateType = $this->detectTemplateType($templateFile, $content);
        }

        $analysis = [
            'variables' => [],
            'usage' => [],
            'contexts' => [],
        ];

        if ($templateType === 'antlers') {
            $analysis = $this->extractAntlersVariables($content, $templateFile, $detectTypes, $groupByContext);
        } elseif ($templateType === 'blade') {
            $analysis = $this->extractBladeVariables($content, $templateFile, $detectTypes, $groupByContext);
        }

        // Include partials analysis if requested
        if ($includePartials) {
            $partialAnalysis = $this->analyzeReferencedPartials($content, $templateType, $detectTypes, $groupByContext);
            $analysis = $this->mergeAnalysis($analysis, $partialAnalysis);
        }

        return $analysis;
    }

    /**
     * Extract variables from Antlers templates.
     *
     *
     * @return array<string, mixed>
     */
    private function extractAntlersVariables(string $content, string $templateFile, bool $detectTypes, bool $groupByContext): array
    {
        $variables = [];
        $usage = [];
        $contexts = [];

        // Extract variable references
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*)\s*\}\}/', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $match) {
            $variable = $match[0];
            $position = $match[1];

            // Parse variable components
            $parts = explode('.', $variable);
            $rootVar = $parts[0];
            $isChained = count($parts) > 1;

            // Initialize variable data
            if (! isset($variables[$rootVar])) {
                $variables[$rootVar] = [
                    'name' => $rootVar,
                    'type' => 'unknown',
                    'complexity' => $isChained ? 'complex' : 'simple',
                    'usages' => [],
                    'properties' => [],
                    'contexts' => [],
                    'template_files' => [],
                ];
            }

            // Track usage
            $lineNumber = substr_count(substr($content, 0, $position), "\n") + 1;
            $variables[$rootVar]['usages'][] = [
                'template' => $templateFile,
                'line' => $lineNumber,
                'full_expression' => $variable,
                'context' => $this->getVariableContext($content, $position),
            ];

            $variables[$rootVar]['template_files'][] = $templateFile;

            // Track chained properties
            if ($isChained) {
                $variables[$rootVar]['properties'] = array_merge(
                    $variables[$rootVar]['properties'],
                    array_slice($parts, 1)
                );
                $variables[$rootVar]['complexity'] = 'complex';
            }

            // Detect type if enabled
            if ($detectTypes) {
                $detectedType = $this->detectVariableType($variable, $content, $position);
                if ($detectedType !== 'unknown') {
                    $variables[$rootVar]['type'] = $detectedType;
                }
            }

            // Group by context if enabled
            if ($groupByContext) {
                $context = $this->getDetailedContext($content, $position);
                $variables[$rootVar]['contexts'][] = $context;

                if (! isset($contexts[$context])) {
                    $contexts[$context] = [];
                }
                $contexts[$context][] = $rootVar;
            }

            // Track usage frequency
            $usage[$rootVar] = ($usage[$rootVar] ?? 0) + 1;
        }

        // Remove duplicates and clean up
        foreach ($variables as $name => &$variable) {
            $variable['properties'] = array_unique($variable['properties']);
            $variable['template_files'] = array_unique($variable['template_files']);
            $variable['contexts'] = array_unique($variable['contexts']);
            $variable['usage_count'] = count($variable['usages']);
        }

        return [
            'variables' => $variables,
            'usage' => $usage,
            'contexts' => $contexts,
        ];
    }

    /**
     * Extract variables from Blade templates.
     *
     *
     * @return array<string, mixed>
     */
    private function extractBladeVariables(string $content, string $templateFile, bool $detectTypes, bool $groupByContext): array
    {
        $variables = [];
        $usage = [];
        $contexts = [];

        // Extract PHP variable references
        preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*(?:->[a-zA-Z_][a-zA-Z0-9_]*)*)/', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $match) {
            $variable = $match[0];
            $position = $match[1];

            // Parse variable components
            $parts = explode('->', $variable);
            $rootVar = $parts[0];
            $isChained = count($parts) > 1;

            // Initialize variable data
            if (! isset($variables[$rootVar])) {
                $variables[$rootVar] = [
                    'name' => $rootVar,
                    'type' => 'unknown',
                    'complexity' => $isChained ? 'complex' : 'simple',
                    'usages' => [],
                    'properties' => [],
                    'contexts' => [],
                    'template_files' => [],
                ];
            }

            // Track usage
            $lineNumber = substr_count(substr($content, 0, $position), "\n") + 1;
            $variables[$rootVar]['usages'][] = [
                'template' => $templateFile,
                'line' => $lineNumber,
                'full_expression' => '$' . $variable,
                'context' => $this->getVariableContext($content, $position),
            ];

            $variables[$rootVar]['template_files'][] = $templateFile;

            // Track chained properties
            if ($isChained) {
                $variables[$rootVar]['properties'] = array_merge(
                    $variables[$rootVar]['properties'],
                    array_slice($parts, 1)
                );
                $variables[$rootVar]['complexity'] = 'complex';
            }

            // Detect type if enabled
            if ($detectTypes) {
                $detectedType = $this->detectVariableType('$' . $variable, $content, $position);
                if ($detectedType !== 'unknown') {
                    $variables[$rootVar]['type'] = $detectedType;
                }
            }

            // Group by context if enabled
            if ($groupByContext) {
                $context = $this->getDetailedContext($content, $position);
                $variables[$rootVar]['contexts'][] = $context;

                if (! isset($contexts[$context])) {
                    $contexts[$context] = [];
                }
                $contexts[$context][] = $rootVar;
            }

            // Track usage frequency
            $usage[$rootVar] = ($usage[$rootVar] ?? 0) + 1;
        }

        // Remove duplicates and clean up
        foreach ($variables as $name => &$variable) {
            $variable['properties'] = array_unique($variable['properties']);
            $variable['template_files'] = array_unique($variable['template_files']);
            $variable['contexts'] = array_unique($variable['contexts']);
            $variable['usage_count'] = count($variable['usages']);
        }

        return [
            'variables' => $variables,
            'usage' => $usage,
            'contexts' => $contexts,
        ];
    }

    /**
     * Detect template type from path and content.
     */
    private function detectTemplateType(string $path, string $content): string
    {
        if (str_contains($path, '.antlers.html') || str_contains($content, '{{')) {
            return 'antlers';
        }

        if (str_contains($path, '.blade.php') || str_contains($content, '$')) {
            return 'blade';
        }

        return 'unknown';
    }

    /**
     * Detect variable type from usage context.
     */
    private function detectVariableType(string $variable, string $content, int $position): string
    {
        $surroundingContent = substr($content, max(0, $position - 100), 200);

        // Look for type hints in surrounding context
        if (preg_match('/\bcount\s*\(\s*' . preg_quote($variable, '/') . '\s*\)/', $surroundingContent)) {
            return 'array|collection';
        }

        if (preg_match('/\bforeach\s*\(\s*' . preg_quote($variable, '/') . '/', $surroundingContent)) {
            return 'iterable';
        }

        if (preg_match('/' . preg_quote($variable, '/') . '\s*->\s*(title|name|slug)/', $surroundingContent)) {
            return 'object|model';
        }

        if (preg_match('/' . preg_quote($variable, '/') . '\s*\.\s*(title|name|slug)/', $surroundingContent)) {
            return 'object|array';
        }

        if (preg_match('/' . preg_quote($variable, '/') . '\s*[<>=]/', $surroundingContent)) {
            return 'comparable';
        }

        // Check common Statamic variable patterns
        $baseVar = preg_replace('/[\$\{\}]/', '', explode('.', explode('->', $variable)[0])[0]);

        $statamicTypes = [
            'entries' => 'Statamic\Entries\EntryCollection',
            'entry' => 'Statamic\Entries\Entry',
            'collection' => 'Statamic\Collections\Collection',
            'user' => 'Statamic\Auth\User',
            'users' => 'Statamic\Auth\UserCollection',
            'site' => 'Statamic\Sites\Site',
            'sites' => 'Statamic\Sites\Sites',
            'taxonomy' => 'Statamic\Taxonomies\Taxonomy',
            'term' => 'Statamic\Taxonomies\Term',
            'terms' => 'Statamic\Taxonomies\TermCollection',
            'global' => 'Statamic\Globals\GlobalSet',
            'globals' => 'Collection',
            'asset' => 'Statamic\Assets\Asset',
            'assets' => 'Statamic\Assets\AssetCollection',
        ];

        if (isset($statamicTypes[$baseVar])) {
            return $statamicTypes[$baseVar];
        }

        return 'unknown';
    }

    /**
     * Get variable context.
     */
    private function getVariableContext(string $content, int $position): string
    {
        $before = substr($content, max(0, $position - 50), 50);
        $after = substr($content, $position, 50);

        if (str_contains($before, 'foreach') || str_contains($before, 'collection:')) {
            return 'loop';
        }

        if (str_contains($before, 'if') || str_contains($before, 'unless')) {
            return 'conditional';
        }

        if (str_contains($before, '<title>') || str_contains($after, '</title>')) {
            return 'head';
        }

        if (preg_match('/<h[1-6]/', $before)) {
            return 'heading';
        }

        return 'content';
    }

    /**
     * Get detailed context.
     */
    private function getDetailedContext(string $content, int $position): string
    {
        $lineStart = strrpos(substr($content, 0, $position), "\n");
        $lineEnd = strpos($content, "\n", $position);

        if ($lineStart === false) {
            $lineStart = 0;
        }
        if ($lineEnd === false) {
            $lineEnd = strlen($content);
        }

        $line = substr($content, $lineStart, $lineEnd - $lineStart);

        // Classify the line context
        if (preg_match('/@foreach|collection:/', $line)) {
            return 'loop';
        }
        if (preg_match('/@if|@unless|\{\{\s*if/', $line)) {
            return 'conditional';
        }
        if (preg_match('/<title>|<meta/', $line)) {
            return 'head';
        }
        if (preg_match('/<h[1-6]/', $line)) {
            return 'heading';
        }
        if (preg_match('/<a\s+href/', $line)) {
            return 'link';
        }
        if (preg_match('/<img\s+/', $line)) {
            return 'image';
        }
        if (preg_match('/@extends|@include|partial:/', $line)) {
            return 'template_structure';
        }

        return 'content';
    }

    /**
     * Merge variable data.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $new
     *
     * @return array<string, mixed>
     */
    private function mergeVariableData(array $existing, array $new): array
    {
        return [
            'name' => $existing['name'],
            'type' => $existing['type'] !== 'unknown' ? $existing['type'] : $new['type'],
            'complexity' => $existing['complexity'] === 'complex' ? 'complex' : $new['complexity'],
            'usages' => array_merge($existing['usages'], $new['usages']),
            'properties' => array_unique(array_merge($existing['properties'], $new['properties'])),
            'contexts' => array_unique(array_merge($existing['contexts'], $new['contexts'])),
            'template_files' => array_unique(array_merge($existing['template_files'], $new['template_files'])),
            'usage_count' => count($existing['usages']) + count($new['usages']),
        ];
    }

    /**
     * Analyze referenced partials.
     *
     *
     * @return array<string, mixed>
     */
    private function analyzeReferencedPartials(string $content, string $templateType, bool $detectTypes, bool $groupByContext): array
    {
        $partialAnalysis = ['variables' => [], 'usage' => [], 'contexts' => []];

        // Find partial references and analyze them if files exist
        if ($templateType === 'antlers') {
            preg_match_all('/\{\{\s*partial:\s*([^}\s]+)/', $content, $matches);
        } else {
            preg_match_all('/@include\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches);
        }

        foreach ($matches[1] as $partialName) {
            // Try to find and analyze the partial file
            $partialPath = resource_path("views/{$partialName}");
            if (! file_exists($partialPath)) {
                $partialPath .= '.antlers.html';
            }
            if (! file_exists($partialPath)) {
                $partialPath = str_replace('.antlers.html', '.blade.php', $partialPath);
            }

            if (file_exists($partialPath)) {
                $partialContent = file_get_contents($partialPath);
                $partialAnalysisResult = $this->analyzeTemplateVariables(
                    ['content' => $partialContent, 'relative_path' => $partialName],
                    $templateType,
                    false, // Avoid infinite recursion
                    $detectTypes,
                    $groupByContext
                );

                $partialAnalysis = $this->mergeAnalysis($partialAnalysis, $partialAnalysisResult);
            }
        }

        return $partialAnalysis;
    }

    /**
     * Merge analysis results.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $new
     *
     * @return array<string, mixed>
     */
    private function mergeAnalysis(array $existing, array $new): array
    {
        $merged = [
            'variables' => $existing['variables'],
            'usage' => $existing['usage'],
            'contexts' => $existing['contexts'],
        ];

        // Merge variables
        foreach ($new['variables'] as $name => $data) {
            if (isset($merged['variables'][$name])) {
                $merged['variables'][$name] = $this->mergeVariableData($merged['variables'][$name], $data);
            } else {
                $merged['variables'][$name] = $data;
            }
        }

        // Merge usage
        foreach ($new['usage'] as $name => $count) {
            $merged['usage'][$name] = ($merged['usage'][$name] ?? 0) + $count;
        }

        // Merge contexts
        foreach ($new['contexts'] as $context => $vars) {
            if (isset($merged['contexts'][$context])) {
                $merged['contexts'][$context] = array_unique(array_merge($merged['contexts'][$context], $vars));
            } else {
                $merged['contexts'][$context] = $vars;
            }
        }

        return $merged;
    }

    /**
     * Generate type suggestions.
     *
     * @param  array<string, mixed>  $variables
     *
     * @return array<string, mixed>
     */
    private function generateTypeSuggestions(array $variables): array
    {
        $suggestions = [
            'typescript' => [],
            'php' => [],
            'statamic' => [],
        ];

        foreach ($variables as $name => $data) {
            if ($data['type'] !== 'unknown') {
                // TypeScript interface
                $suggestions['typescript'][$name] = $this->generateTypeScriptType($name, $data);

                // PHP type hint
                $suggestions['php'][$name] = $this->generatePHPType($name, $data);

                // Statamic-specific type
                if (str_contains($data['type'], 'Statamic\\')) {
                    $suggestions['statamic'][$name] = $data['type'];
                }
            }
        }

        return $suggestions;
    }

    /**
     * Generate TypeScript type definition.
     *
     * @param  array<string, mixed>  $data
     */
    private function generateTypeScriptType(string $name, array $data): string
    {
        $type = $data['type'];
        $properties = $data['properties'];

        if (str_contains($type, 'Collection')) {
            $itemType = str_replace('Collection', '', $type);

            return "{$name}: {$itemType}[]";
        }

        if (! empty($properties)) {
            $propertyTypes = array_map(fn ($prop) => "{$prop}: string", $properties);

            return "{$name}: { " . implode('; ', $propertyTypes) . ' }';
        }

        $tsTypes = [
            'string' => 'string',
            'integer' => 'number',
            'boolean' => 'boolean',
            'array' => 'any[]',
            'object' => 'Record<string, any>',
        ];

        return "{$name}: " . ($tsTypes[strtolower($type)] ?? 'any');
    }

    /**
     * Generate PHP type hint.
     *
     * @param  array<string, mixed>  $data
     */
    private function generatePHPType(string $name, array $data): string
    {
        $type = $data['type'];

        if (str_contains($type, 'Statamic\\')) {
            return $type;
        }

        $phpTypes = [
            'string' => 'string',
            'integer' => 'int',
            'boolean' => 'bool',
            'array' => 'array',
            'object' => 'object',
            'iterable' => 'iterable',
            'collection' => 'Collection',
        ];

        return $phpTypes[strtolower($type)] ?? 'mixed';
    }

    /**
     * Generate variable documentation.
     *
     * @param  array<string, mixed>  $variables
     *
     * @return array<string, mixed>
     */
    private function generateVariableDocumentation(array $variables): array
    {
        $documentation = [];

        foreach ($variables as $name => $data) {
            $doc = [
                'name' => $name,
                'type' => $data['type'],
                'description' => $this->generateVariableDescription($name, $data),
                'usage_examples' => array_slice($data['usages'], 0, 3),
                'properties' => $data['properties'],
                'contexts' => array_unique($data['contexts']),
                'templates' => array_unique($data['template_files']),
            ];

            $documentation[$name] = $doc;
        }

        return $documentation;
    }

    /**
     * Generate variable description.
     *
     * @param  array<string, mixed>  $data
     */
    private function generateVariableDescription(string $name, array $data): string
    {
        $contexts = array_unique($data['contexts']);
        $properties = $data['properties'];
        $usageCount = $data['usage_count'];

        $description = "Variable '{$name}' ";

        if ($data['type'] !== 'unknown') {
            $description .= "of type '{$data['type']}' ";
        }

        $description .= "used {$usageCount} time(s)";

        if (! empty($contexts)) {
            $description .= ' in contexts: ' . implode(', ', $contexts);
        }

        if (! empty($properties)) {
            $description .= '. Accesses properties: ' . implode(', ', array_slice($properties, 0, 5));
            if (count($properties) > 5) {
                $description .= ' and ' . (count($properties) - 5) . ' more';
            }
        }

        return $description;
    }

    /**
     * Get most used variables.
     *
     * @param  array<string, int>  $usage
     *
     * @return array<string>
     */
    private function getMostUsedVariables(array $usage): array
    {
        arsort($usage);

        return array_slice(array_keys($usage), 0, 5);
    }
}
