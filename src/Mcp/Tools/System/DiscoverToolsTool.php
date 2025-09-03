<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Services\SchemaIntrospectionService;
use Cboxdk\StatamicMcp\Mcp\Support\ToolCache;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use ReflectionClass;

#[Title('Discover MCP Tools')]
#[IsReadOnly]
class DiscoverToolsTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.system.tools.discover';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Discover available MCP tools with pagination support. Supports filtering by domain/action, search, and optional schemas/examples. Use limit/offset for pagination.';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('domain')
            ->description('Filter by domain (blueprints, collections, entries, etc.)')
            ->optional()
            ->string('action')
            ->description('Filter by action (list, get, create, update, delete)')
            ->optional()
            ->boolean('include_schemas')
            ->description('Include detailed parameter schemas for each tool')
            ->optional()
            ->boolean('include_examples')
            ->description('Include usage examples for each tool')
            ->optional()
            ->boolean('include_annotations')
            ->description('Include tool annotations (readonly, deprecated, etc.)')
            ->optional()
            ->string('search')
            ->description('Search tools by name or description')
            ->optional()
            ->integer('limit')
            ->description('Maximum number of tools to return (default: 50, max: 100)')
            ->optional()
            ->integer('offset')
            ->description('Number of tools to skip (for pagination)')
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
        $domain = $arguments['domain'] ?? null;
        $action = $arguments['action'] ?? null;
        $includeSchemas = $arguments['include_schemas'] ?? false;
        $includeExamples = $arguments['include_examples'] ?? false;
        $includeAnnotations = $arguments['include_annotations'] ?? false;
        $search = $arguments['search'] ?? null;
        $limit = min($arguments['limit'] ?? 50, 100); // Default 50, max 100
        $offset = max($arguments['offset'] ?? 0, 0);

        try {
            $allTools = $this->discoverAllTools();
            $filteredTools = $this->filterTools($allTools, $domain, $action, $search);
            $totalFilteredCount = (int) count($filteredTools);

            // Apply pagination
            $paginatedTools = array_slice($filteredTools, $offset, $limit);
            $returnedCount = (int) count($paginatedTools);

            $toolCatalog = [];
            $statistics = [
                'total_tools' => $totalFilteredCount,
                'returned_tools' => 0,
                'domains' => [],
                'actions' => [],
                'readonly_tools' => 0,
                'write_tools' => 0,
            ];

            // Calculate statistics from ALL filtered tools (not just paginated)
            foreach ($filteredTools as $toolData) {
                $statistics['domains'][$toolData['domain']] = ($statistics['domains'][$toolData['domain']] ?? 0) + 1;
                $statistics['actions'][$toolData['action']] = ($statistics['actions'][$toolData['action']] ?? 0) + 1;

                if (in_array('IsReadOnly', $toolData['annotations'])) {
                    $statistics['readonly_tools']++;
                } else {
                    $statistics['write_tools']++;
                }
            }

            // Process only paginated tools for response
            foreach ($paginatedTools as $toolData) {
                $toolInfo = [
                    'name' => $toolData['name'],
                    'description' => $toolData['description'],
                    'domain' => $toolData['domain'],
                    'action' => $toolData['action'],
                    'class' => $toolData['class'],
                ];

                if ($includeAnnotations) {
                    $toolInfo['annotations'] = $toolData['annotations'];
                }

                if ($includeSchemas) {
                    $toolInfo['schema'] = $toolData['schema'];
                }

                if ($includeExamples) {
                    $toolInfo['examples'] = $this->generateToolExamples($toolData);
                }

                $toolCatalog[] = $toolInfo;
                $statistics['returned_tools']++;
            }

            $response = [
                'tools' => $toolCatalog,
                'statistics' => $statistics,
                'pagination' => [
                    'total_count' => $totalFilteredCount,
                    'returned_count' => $returnedCount,
                    'offset' => $offset,
                    'limit' => $limit,
                    'has_more' => (int) ($offset + $limit) < $totalFilteredCount,
                    'next_offset' => ((int) ($offset + $limit) < $totalFilteredCount) ? (int) ($offset + $limit) : null,
                ],
                'meta' => [
                    'filtered_by_domain' => $domain,
                    'filtered_by_action' => $action,
                    'search_query' => $search,
                    'schemas_included' => $includeSchemas,
                    'examples_included' => $includeExamples,
                    'annotations_included' => $includeAnnotations,
                ],
                'tool_naming_convention' => [
                    'pattern' => 'statamic.{domain}.{action}',
                    'domains' => array_keys($statistics['domains']),
                    'actions' => array_keys($statistics['actions']),
                ],
            ];

            // Add warnings for large responses
            $warnings = [];
            if ($includeSchemas && $includeExamples && $returnedCount > 20) {
                $warnings[] = 'Response may be large with both schemas and examples included for ' . $returnedCount . ' tools. Consider using smaller limit or excluding one option.';
            }
            if ($returnedCount > 50) {
                $warnings[] = 'Large tool count (' . $returnedCount . '). Consider using domain/action filters for better performance.';
            }

            if (! empty($warnings)) {
                $response['warnings'] = $warnings;
            }

            return $response;

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to discover tools: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Discover all MCP tools in the project.
     *
     * @return array<array<string, mixed>>
     */
    private function discoverAllTools(): array
    {
        // Check cache first
        $cached = ToolCache::getCachedDiscovery($this->getToolName(), [__DIR__ . '/../../Tools']);
        if ($cached !== null) {
            return $cached;
        }

        $tools = [];
        $toolsDirectory = __DIR__ . '/../../Tools';

        $toolClasses = $this->findToolClasses($toolsDirectory);

        foreach ($toolClasses as $className) {
            try {
                if (class_exists($className)) {
                    /** @var class-string $className */
                    $toolData = $this->analyzeToolClass($className);
                    if ($toolData) {
                        $tools[] = $toolData;
                    }
                }
            } catch (\Exception $e) {
                // Skip invalid tool classes
                continue;
            }
        }

        // Cache the results with dependency tracking
        return ToolCache::cacheDiscovery($this->getToolName(), $tools, [$toolsDirectory]);
    }

    /**
     * Find all tool classes in the tools directory.
     *
     *
     * @return array<string>
     */
    private function findToolClasses(string $directory): array
    {
        $classes = [];

        if (! is_dir($directory)) {
            return $classes;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $relativePath = substr($file->getPathname(), strlen(__DIR__ . '/../../Tools/'));
                $className = 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

                if (class_exists($className) && is_subclass_of($className, BaseStatamicTool::class)) {
                    $classes[] = $className;
                }
            }
        }

        return $classes;
    }

    /**
     * Analyze a tool class to extract metadata.
     *
     * @param  class-string  $className
     *
     * @return array<string, mixed>|null
     */
    private function analyzeToolClass(string $className): ?array
    {
        try {
            $reflection = new ReflectionClass($className);

            // Skip abstract classes
            if ($reflection->isAbstract()) {
                return null;
            }

            $tool = new $className;

            // Ensure it's actually a BaseStatamicTool
            if (! $tool instanceof BaseStatamicTool) {
                return null;
            }

            // Get tool name and description
            $name = $tool->name();
            $description = $tool->description();

            // Parse domain and action from name
            $nameParts = explode('.', $name);
            $domain = $nameParts[1] ?? 'unknown';
            $action = $nameParts[2] ?? 'unknown';

            // Get annotations
            $annotations = $this->extractAnnotations($reflection);

            // Get schema
            $schema = $this->extractToolSchema($tool);

            return [
                'name' => $name,
                'description' => $description,
                'domain' => $domain,
                'action' => $action,
                'class' => $className,
                'annotations' => $annotations,
                'schema' => $schema,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract annotations from a tool class.
     *
     * @param  ReflectionClass<object>  $reflection
     *
     * @return array<string>
     */
    private function extractAnnotations(ReflectionClass $reflection): array
    {
        $annotations = [];

        // Check for PHP 8 attributes
        $attributes = $reflection->getAttributes();
        foreach ($attributes as $attribute) {
            $attributeName = $attribute->getName();
            $shortName = substr($attributeName, strrpos($attributeName, '\\') + 1);
            $annotations[] = $shortName;
        }

        return $annotations;
    }

    /**
     * Extract tool schema information.
     *
     * @param  BaseStatamicTool  $tool
     *
     * @return array<string, mixed>
     */
    private function extractToolSchema($tool): array
    {
        $schemaService = new SchemaIntrospectionService;

        return $schemaService->extractToolSchema($tool);
    }

    /**
     * Filter tools based on criteria.
     *
     * @param  array<array<string, mixed>>  $tools
     *
     * @return array<array<string, mixed>>
     */
    private function filterTools(array $tools, ?string $domain, ?string $action, ?string $search): array
    {
        return array_filter($tools, function ($tool) use ($domain, $action, $search) {
            // Filter by domain
            if ($domain && $tool['domain'] !== $domain) {
                return false;
            }

            // Filter by action
            if ($action && $tool['action'] !== $action) {
                return false;
            }

            // Filter by search
            if ($search) {
                $searchLower = strtolower($search);
                $nameMatch = str_contains(strtolower($tool['name']), $searchLower);
                $descriptionMatch = str_contains(strtolower($tool['description']), $searchLower);

                if (! $nameMatch && ! $descriptionMatch) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Generate usage examples for a tool.
     *
     * @param  array<string, mixed>  $toolData
     *
     * @return array<array<string, mixed>>
     */
    private function generateToolExamples(array $toolData): array
    {
        $examples = [];
        $schema = $toolData['schema'];
        $domain = $toolData['domain'];
        $action = $toolData['action'];

        // Generate basic example
        $basicExample = [
            'title' => 'Basic Usage',
            'description' => "Basic usage of {$toolData['name']}",
            'parameters' => [],
        ];

        // Add required parameters with example values
        foreach ($schema['required_parameters'] as $param) {
            $paramConfig = $schema['parameters'][$param];
            $basicExample['parameters'][$param] = $this->generateExampleValue($param, $paramConfig, $domain);
        }

        if (! empty($basicExample['parameters'])) {
            $examples[] = $basicExample;
        }

        // Generate advanced example with optional parameters
        if (! empty($schema['optional_parameters'])) {
            $advancedExample = [
                'title' => 'Advanced Usage',
                'description' => 'Advanced usage with optional parameters',
                'parameters' => $basicExample['parameters'],
            ];

            // Add some optional parameters
            $optionalParams = array_slice($schema['optional_parameters'], 0, 3);
            foreach ($optionalParams as $param) {
                $paramConfig = $schema['parameters'][$param];
                $advancedExample['parameters'][$param] = $this->generateExampleValue($param, $paramConfig, $domain);
            }

            $examples[] = $advancedExample;
        }

        return $examples;
    }

    /**
     * Generate example value for a parameter.
     *
     * @param  array<string, mixed>  $paramConfig
     *
     * @return mixed
     */
    private function generateExampleValue(string $paramName, array $paramConfig, string $domain)
    {
        $type = $paramConfig['type'];

        switch ($type) {
            case 'string':
                return $this->generateStringExample($paramName, $domain);

            case 'integer':
                return $this->generateIntegerExample($paramName);

            case 'boolean':
                return true;

            case 'raw':
                if (isset($paramConfig['items'])) {
                    return $this->generateArrayExample($paramName, $domain);
                }

                return $this->generateObjectExample($paramName, $domain);

            default:
                return 'example_value';
        }
    }

    /**
     * Generate string example value.
     */
    private function generateStringExample(string $paramName, string $domain): string
    {
        $examples = [
            'handle' => $domain === 'collections' ? 'blog' : 'example',
            'title' => 'Example Title',
            'name' => 'Example Name',
            'slug' => 'example-slug',
            'namespace' => 'collections.blog',
            'collection' => 'blog',
            'taxonomy' => 'tags',
            'site' => 'default',
            'locale' => 'en_US',
            'email' => 'user@example.com',
            'password' => 'secure_password',
            'role' => 'editor',
            'user_id' => 'user@example.com',
        ];

        return $examples[$paramName] ?? 'example_value';
    }

    /**
     * Generate integer example value.
     */
    private function generateIntegerExample(string $paramName): int
    {
        $examples = [
            'limit' => 10,
            'max_items' => 5,
            'max_files' => 3,
            'page' => 1,
            'per_page' => 20,
        ];

        return $examples[$paramName] ?? 10;
    }

    /**
     * Generate array example value.
     *
     *
     * @return array<string>
     */
    private function generateArrayExample(string $paramName, string $domain): array
    {
        $examples = [
            'collections' => ['blog', 'pages'],
            'sites' => ['default', 'en'],
            'roles' => ['editor', 'author'],
            'permissions' => ['access cp', 'view entries'],
            'fields' => ['title', 'content'],
        ];

        return $examples[$paramName] ?? ['example1', 'example2'];
    }

    /**
     * Generate object example value.
     *
     *
     * @return array<string, mixed>
     */
    private function generateObjectExample(string $paramName, string $domain): array
    {
        $examples = [
            'data' => ['title' => 'Example Title', 'content' => 'Example content'],
            'fields' => ['title' => ['type' => 'text'], 'content' => ['type' => 'markdown']],
            'config' => ['option1' => true, 'option2' => 'value'],
            'attributes' => ['custom_field' => 'custom_value'],
        ];

        return $examples[$paramName] ?? ['key' => 'value'];
    }
}
