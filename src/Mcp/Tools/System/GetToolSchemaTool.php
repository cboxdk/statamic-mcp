<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use ReflectionClass;

#[Title('Get Tool Schema')]
#[IsReadOnly]
class GetToolSchemaTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.system.tools.schema';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Get detailed schema, parameters, and documentation for a specific MCP tool';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('tool_name')
            ->description('Full tool name (e.g., statamic.collections.list)')
            ->required()
            ->boolean('include_examples')
            ->description('Include parameter usage examples')
            ->optional()
            ->boolean('include_validation_rules')
            ->description('Include parameter validation information')
            ->optional()
            ->boolean('include_related_tools')
            ->description('Include related tools in the same domain')
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
        $toolName = $arguments['tool_name'];
        $includeExamples = $arguments['include_examples'] ?? true;
        $includeValidationRules = $arguments['include_validation_rules'] ?? true;
        $includeRelatedTools = $arguments['include_related_tools'] ?? true;

        try {
            // Find the tool class
            $toolClass = $this->findToolClass($toolName);

            if (! $toolClass) {
                return $this->createErrorResponse("Tool '{$toolName}' not found", [
                    'suggestion' => 'Use statamic.system.tools.discover to see available tools',
                ])->toArray();
            }

            $tool = new $toolClass;

            // Ensure it's actually a BaseStatamicTool
            if (! $tool instanceof BaseStatamicTool) {
                return $this->createErrorResponse('Tool class must extend BaseStatamicTool')->toArray();
            }

            /** @var class-string $toolClass */
            $reflection = new ReflectionClass($toolClass);

            // Get basic tool information
            $toolInfo = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'class' => $toolClass,
                'namespace' => $reflection->getNamespaceName(),
                'file_path' => $reflection->getFileName(),
            ];

            // Extract domain and action
            $nameParts = explode('.', $toolName);
            $domain = $nameParts[1] ?? 'unknown';
            $action = $nameParts[2] ?? 'unknown';

            $toolInfo['domain'] = $domain;
            $toolInfo['action'] = $action;

            // Get annotations
            $toolInfo['annotations'] = $this->extractAnnotations($reflection);

            // Get detailed schema
            $schema = $this->extractDetailedSchema($tool);

            // Add validation rules if requested
            if ($includeValidationRules) {
                $schema = $this->addValidationRules($schema, $tool);
            }

            // Add examples if requested
            if ($includeExamples) {
                $schema['examples'] = $this->generateParameterExamples($schema, $domain, $action);
            }

            // Get related tools if requested
            $relatedTools = [];
            if ($includeRelatedTools) {
                $relatedTools = $this->findRelatedTools($domain, $toolName);
            }

            return [
                'tool' => $toolInfo,
                'schema' => $schema,
                'usage_notes' => $this->generateUsageNotes($toolInfo, $schema),
                'related_tools' => $relatedTools,
                'meta' => [
                    'examples_included' => $includeExamples,
                    'validation_rules_included' => $includeValidationRules,
                    'related_tools_included' => $includeRelatedTools,
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to get tool schema: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Find tool class by name.
     */
    private function findToolClass(string $toolName): ?string
    {
        $toolsDirectory = __DIR__ . '/../../Tools';

        if (! is_dir($toolsDirectory)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($toolsDirectory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $relativePath = substr($file->getPathname(), strlen($toolsDirectory . '/'));
                $className = 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

                if (class_exists($className) && is_subclass_of($className, BaseStatamicTool::class)) {
                    try {
                        $tool = new $className;
                        if ($tool->name() === $toolName) {
                            return $className;
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract annotations from a tool class.
     *
     * @param  ReflectionClass<object>  $reflection
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractAnnotations(ReflectionClass $reflection): array
    {
        $annotations = [];

        $attributes = $reflection->getAttributes();
        foreach ($attributes as $attribute) {
            $attributeName = $attribute->getName();
            $shortName = substr($attributeName, strrpos($attributeName, '\\') + 1);

            $attributeData = [
                'name' => $shortName,
                'full_name' => $attributeName,
            ];

            // Get attribute arguments if any
            $args = $attribute->getArguments();
            if (! empty($args)) {
                $attributeData['arguments'] = $args;
            }

            $annotations[] = $attributeData;
        }

        return $annotations;
    }

    /**
     * Extract detailed schema information.
     *
     * @param  BaseStatamicTool  $tool
     *
     * @return array<string, mixed>
     */
    private function extractDetailedSchema($tool): array
    {
        try {
            $reflection = new \ReflectionClass($tool);
            $defineSchemaMethod = $reflection->getMethod('defineSchema');
            $defineSchemaMethod->setAccessible(true);

            $mockSchema = new class
            {
                /** @var array<string, array<string, mixed>> */
                public array $parameters = [];

                private ?string $currentParameter = null;

                public function string(string $name): self
                {
                    $this->currentParameter = $name;
                    $this->parameters[$name] = [
                        'name' => $name,
                        'type' => 'string',
                        'required' => false,
                        'description' => null,
                    ];

                    return $this;
                }

                public function integer(string $name): self
                {
                    $this->currentParameter = $name;
                    $this->parameters[$name] = [
                        'name' => $name,
                        'type' => 'integer',
                        'required' => false,
                        'description' => null,
                    ];

                    return $this;
                }

                public function boolean(string $name): self
                {
                    $this->currentParameter = $name;
                    $this->parameters[$name] = [
                        'name' => $name,
                        'type' => 'boolean',
                        'required' => false,
                        'description' => null,
                    ];

                    return $this;
                }

                /** @param  array<string, mixed>  $config */
                public function raw(string $name, array $config): self
                {
                    $this->currentParameter = $name;
                    $this->parameters[$name] = [
                        'name' => $name,
                        'type' => 'raw',
                        'config' => $config,
                        'required' => false,
                        'description' => null,
                    ];

                    return $this;
                }

                public function description(string $description): self
                {
                    if ($this->currentParameter && isset($this->parameters[$this->currentParameter])) {
                        $this->parameters[$this->currentParameter]['description'] = $description;
                    }

                    return $this;
                }

                public function required(): self
                {
                    if ($this->currentParameter && isset($this->parameters[$this->currentParameter])) {
                        $this->parameters[$this->currentParameter]['required'] = true;
                    }

                    return $this;
                }

                public function optional(): self
                {
                    if ($this->currentParameter && isset($this->parameters[$this->currentParameter])) {
                        $this->parameters[$this->currentParameter]['required'] = false;
                    }

                    return $this;
                }
            };

            $defineSchemaMethod->invoke($tool, $mockSchema);

            $parameters = $mockSchema->parameters;

            return [
                'parameters' => $parameters,
                'parameter_count' => count($parameters),
                'required_parameters' => array_values(array_filter($parameters, fn ($p) => $p['required'])),
                'optional_parameters' => array_values(array_filter($parameters, fn ($p) => ! $p['required'])),
                'parameter_types' => array_count_values(array_column($parameters, 'type')),
            ];
        } catch (\Exception $e) {
            return [
                'parameters' => [],
                'parameter_count' => 0,
                'required_parameters' => [],
                'optional_parameters' => [],
                'parameter_types' => [],
                'error' => 'Could not extract schema: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Add validation rules to schema.
     *
     * @param  array<string, mixed>  $schema
     * @param  BaseStatamicTool  $tool
     *
     * @return array<string, mixed>
     */
    private function addValidationRules(array $schema, $tool): array
    {
        foreach ($schema['parameters'] as $paramName => &$paramData) {
            $paramData['validation'] = $this->getParameterValidationRules($paramName, $paramData);
        }

        return $schema;
    }

    /**
     * Get validation rules for a parameter.
     *
     * @param  array<string, mixed>  $paramData
     *
     * @return array<string, mixed>
     */
    private function getParameterValidationRules(string $paramName, array $paramData): array
    {
        $rules = [];

        // Basic type validation
        switch ($paramData['type']) {
            case 'string':
                $rules['format'] = 'string';
                $rules['min_length'] = 1;
                if (in_array($paramName, ['handle', 'slug'])) {
                    $rules['pattern'] = '^[a-z0-9_-]+$';
                    $rules['description'] = 'Must contain only lowercase letters, numbers, hyphens, and underscores';
                }
                if ($paramName === 'email') {
                    $rules['pattern'] = '^[^\s@]+@[^\s@]+\.[^\s@]+$';
                    $rules['description'] = 'Must be a valid email address';
                }
                break;

            case 'integer':
                $rules['format'] = 'integer';
                $rules['minimum'] = 0;
                if (in_array($paramName, ['limit', 'per_page', 'max_items'])) {
                    $rules['maximum'] = 1000;
                }
                break;

            case 'boolean':
                $rules['format'] = 'boolean';
                $rules['allowed_values'] = [true, false];
                break;

            case 'raw':
                if (isset($paramData['config']['type'])) {
                    $rules['format'] = $paramData['config']['type'];

                    if ($paramData['config']['type'] === 'array') {
                        $rules['format'] = 'array';
                        if (isset($paramData['config']['items'])) {
                            $rules['items'] = $paramData['config']['items'];
                        }
                    }
                }
                break;
        }

        // Required field validation
        if ($paramData['required']) {
            $rules['required'] = true;
        }

        return $rules;
    }

    /**
     * Generate parameter examples.
     *
     * @param  array<string, mixed>  $schema
     *
     * @return array<string, mixed>
     */
    private function generateParameterExamples(array $schema, string $domain, string $action): array
    {
        $examples = [
            'minimal' => [],
            'complete' => [],
            'use_cases' => [],
        ];

        // Minimal example (required parameters only)
        foreach ($schema['required_parameters'] as $param) {
            $examples['minimal'][$param['name']] = $this->generateParameterExample($param, $domain);
        }

        // Complete example (all parameters)
        foreach ($schema['parameters'] as $param) {
            $examples['complete'][$param['name']] = $this->generateParameterExample($param, $domain);
        }

        // Generate use case examples
        $examples['use_cases'] = $this->generateUseCaseExamples($schema, $domain, $action);

        return $examples;
    }

    /**
     * Generate example value for a parameter.
     *
     * @param  array<string, mixed>  $param
     *
     * @return mixed
     */
    private function generateParameterExample(array $param, string $domain)
    {
        $name = $param['name'];
        $type = $param['type'];

        // Domain-specific examples
        $domainExamples = [
            'collections' => [
                'handle' => 'blog',
                'collection' => 'blog',
                'title' => 'Blog Posts',
            ],
            'entries' => [
                'collection' => 'blog',
                'id' => 'blog-post-123',
                'title' => 'My First Blog Post',
            ],
            'users' => [
                'email' => 'john@example.com',
                'name' => 'John Doe',
                'role' => 'editor',
            ],
            'sites' => [
                'handle' => 'en',
                'name' => 'English',
                'url' => '/',
                'locale' => 'en_US',
            ],
        ];

        // Try domain-specific example first
        if (isset($domainExamples[$domain][$name])) {
            return $domainExamples[$domain][$name];
        }

        // Generic examples based on parameter name
        $genericExamples = [
            'handle' => 'example',
            'title' => 'Example Title',
            'name' => 'Example Name',
            'slug' => 'example-slug',
            'email' => 'user@example.com',
            'limit' => 10,
            'page' => 1,
            'per_page' => 20,
            'site' => 'default',
            'locale' => 'en_US',
        ];

        if (isset($genericExamples[$name])) {
            return $genericExamples[$name];
        }

        // Type-based examples
        switch ($type) {
            case 'string':
                return 'example_value';
            case 'integer':
                return 10;
            case 'boolean':
                return true;
            case 'raw':
                if (isset($param['config']['type']) && $param['config']['type'] === 'array') {
                    return ['example1', 'example2'];
                }

                return ['key' => 'value'];
            default:
                return null;
        }
    }

    /**
     * Generate use case examples.
     *
     * @param  array<string, mixed>  $schema
     *
     * @return array<array<string, mixed>>
     */
    private function generateUseCaseExamples(array $schema, string $domain, string $action): array
    {
        $useCases = [];

        // Generate use cases based on domain and action
        if ($domain === 'collections' && $action === 'list') {
            $useCases[] = [
                'title' => 'List all collections with details',
                'description' => 'Get a comprehensive list of all collections including their configuration',
                'parameters' => [
                    'include_details' => true,
                    'include_blueprints' => true,
                ],
            ];

            $useCases[] = [
                'title' => 'List collections for a specific site',
                'description' => 'Get collections available for a particular site',
                'parameters' => [
                    'site' => 'en',
                    'include_details' => false,
                ],
            ];
        }

        if ($domain === 'entries' && $action === 'list') {
            $useCases[] = [
                'title' => 'Get recent blog posts',
                'description' => 'Retrieve the 10 most recent published blog entries',
                'parameters' => [
                    'collection' => 'blog',
                    'status' => 'published',
                    'limit' => 10,
                    'sort' => 'date:desc',
                ],
            ];

            $useCases[] = [
                'title' => 'Search entries with pagination',
                'description' => 'Search for entries matching a query with pagination',
                'parameters' => [
                    'collection' => 'blog',
                    'search' => 'Laravel',
                    'page' => 1,
                    'per_page' => 20,
                ],
            ];
        }

        return $useCases;
    }

    /**
     * Find related tools in the same domain.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function findRelatedTools(string $domain, string $excludeToolName): array
    {
        $relatedTools = [];
        $toolsDirectory = __DIR__ . '/../../Tools';

        if (! is_dir($toolsDirectory)) {
            return $relatedTools;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($toolsDirectory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $relativePath = substr($file->getPathname(), strlen($toolsDirectory . '/'));
                $className = 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

                if (class_exists($className) && is_subclass_of($className, BaseStatamicTool::class)) {
                    try {
                        $tool = new $className;
                        $toolName = $tool->name();

                        if ($toolName === $excludeToolName) {
                            continue;
                        }

                        $nameParts = explode('.', $toolName);
                        $toolDomain = $nameParts[1] ?? '';

                        if ($toolDomain === $domain) {
                            $relatedTools[] = [
                                'name' => $toolName,
                                'description' => $tool->description(),
                                'action' => $nameParts[2] ?? 'unknown',
                            ];
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }

        return $relatedTools;
    }

    /**
     * Generate usage notes for a tool.
     *
     * @param  array<string, mixed>  $toolInfo
     * @param  array<string, mixed>  $schema
     *
     * @return array<string>
     */
    private function generateUsageNotes(array $toolInfo, array $schema): array
    {
        $notes = [];

        // Check if tool is read-only
        $isReadOnly = false;
        foreach ($toolInfo['annotations'] as $annotation) {
            if ($annotation['name'] === 'IsReadOnly') {
                $isReadOnly = true;
                break;
            }
        }

        if ($isReadOnly) {
            $notes[] = 'This tool is read-only and will not modify any data';
        } else {
            $notes[] = 'This tool can modify data - use with caution';
        }

        // Parameter notes
        if (count($schema['required_parameters']) > 0) {
            $notes[] = 'Required parameters: ' . implode(', ', array_column($schema['required_parameters'], 'name'));
        }

        if (count($schema['optional_parameters']) > count($schema['parameters']) / 2) {
            $notes[] = 'This tool has many optional parameters - refer to examples for common usage patterns';
        }

        // Domain-specific notes
        $domain = $toolInfo['domain'];
        switch ($domain) {
            case 'entries':
                $notes[] = 'Entry operations may trigger cache clearing and reindexing';
                break;
            case 'collections':
                $notes[] = 'Collection changes may affect multiple entries and blueprints';
                break;
            case 'users':
                $notes[] = 'User operations may affect authentication and permissions';
                break;
        }

        return $notes;
    }
}
