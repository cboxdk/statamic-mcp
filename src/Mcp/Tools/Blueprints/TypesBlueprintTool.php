<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Blueprints;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;

#[Title('Blueprint Type Analysis and Generation')]
#[IsReadOnly]
class TypesBlueprintTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.blueprints.types';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Analyze blueprint structures and generate TypeScript, PHP classes, or JSON Schema definitions from blueprints';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('blueprint_handles')
            ->description('Comma-separated list of blueprint handles to analyze (optional - analyzes all if not specified)')
            ->optional()
            ->string('output_format')
            ->description('Output format: typescript, php, json-schema, or all (default: typescript)')
            ->optional()
            ->string('namespace')
            ->description('Namespace prefix for generated types (e.g., "App\\Models" for PHP, "Types" for TypeScript)')
            ->optional()
            ->boolean('include_relationships')
            ->description('Include relationship field types and references (default: true)')
            ->optional()
            ->boolean('include_validation')
            ->description('Include validation rules in generated types (default: true)')
            ->optional()
            ->boolean('generate_interfaces')
            ->description('Generate interfaces/contracts for TypeScript/PHP (default: false)')
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
        $blueprintHandles = $this->getBlueprintHandles($arguments);
        $outputFormat = $arguments['output_format'] ?? 'typescript';
        $namespace = $arguments['namespace'] ?? null;
        $includeRelationships = $arguments['include_relationships'] ?? true;
        $includeValidation = $arguments['include_validation'] ?? true;
        $generateInterfaces = $arguments['generate_interfaces'] ?? false;

        $blueprints = $this->getBlueprints($blueprintHandles);
        $typeDefinitions = [];

        foreach ($blueprints as $handle => $blueprint) {
            $typeDefinitions[$handle] = $this->analyzeBlueprint(
                $handle,
                $blueprint,
                $outputFormat,
                $namespace,
                $includeRelationships,
                $includeValidation,
                $generateInterfaces
            );
        }

        return [
            'blueprints_analyzed' => count($typeDefinitions),
            'output_format' => $outputFormat,
            'namespace' => $namespace,
            'type_definitions' => $typeDefinitions,
            'summary' => $this->generateSummary($typeDefinitions, $outputFormat),
        ];
    }

    /**
     * Get blueprint handles to analyze.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<int, string>
     */
    private function getBlueprintHandles(array $arguments): array
    {
        if (! empty($arguments['blueprint_handles'])) {
            return array_map('trim', explode(',', $arguments['blueprint_handles']));
        }

        // Get all available blueprints
        $allBlueprints = [];

        // Collection blueprints
        foreach (Blueprint::in('collections') as $blueprint) {
            $allBlueprints[] = "collections.{$blueprint->handle()}";
        }

        // Taxonomy blueprints
        foreach (Blueprint::in('taxonomies') as $blueprint) {
            $allBlueprints[] = "taxonomies.{$blueprint->handle()}";
        }

        // Global blueprints
        foreach (Blueprint::in('globals') as $blueprint) {
            $allBlueprints[] = "globals.{$blueprint->handle()}";
        }

        return $allBlueprints;
    }

    /**
     * Get blueprint instances.
     *
     * @param  array<int, string>  $handles
     *
     * @return array<string, \Statamic\Fields\Blueprint>
     */
    private function getBlueprints(array $handles): array
    {
        $blueprints = [];

        foreach ($handles as $handle) {
            if (str_contains($handle, '.')) {
                [$namespace, $blueprintHandle] = explode('.', $handle, 2);
                $blueprint = Blueprint::find("{$namespace}/{$blueprintHandle}");
            } else {
                $blueprint = Blueprint::find($handle);
            }

            if ($blueprint) {
                $blueprints[$handle] = $blueprint;
            }
        }

        return $blueprints;
    }

    /**
     * Analyze a single blueprint and generate type definitions.
     *
     * @return array<string, mixed>
     */
    private function analyzeBlueprint(
        string $handle,
        \Statamic\Fields\Blueprint $blueprint,
        string $outputFormat,
        ?string $namespace,
        bool $includeRelationships,
        bool $includeValidation,
        bool $generateInterfaces
    ): array {
        $fields = $blueprint->fields()->all();
        $fieldTypes = [];
        $relationships = [];
        $validationRules = [];

        foreach ($fields as $fieldHandle => $field) {
            $fieldConfig = $field->config();
            $fieldType = $fieldConfig['type'] ?? 'text';

            $fieldTypes[$fieldHandle] = [
                'statamic_type' => $fieldType,
                'native_type' => $this->mapToNativeType($fieldType, $outputFormat),
                'nullable' => ! ($fieldConfig['required'] ?? false),
                'display' => $fieldConfig['display'] ?? $fieldHandle,
                'instructions' => $fieldConfig['instructions'] ?? null,
            ];

            // Handle relationships
            if ($includeRelationships && $this->isRelationshipField($fieldType)) {
                $relationships[$fieldHandle] = $this->analyzeRelationship($fieldConfig, $fieldType);
            }

            // Handle validation
            if ($includeValidation && isset($fieldConfig['validate'])) {
                $validationRules[$fieldHandle] = $fieldConfig['validate'];
            }
        }

        $result = [
            'handle' => $handle,
            'title' => $blueprint->title(),
            'field_count' => count($fieldTypes),
            'fields' => $fieldTypes,
        ];

        if ($includeRelationships && ! empty($relationships)) {
            $result['relationships'] = $relationships;
        }

        if ($includeValidation && ! empty($validationRules)) {
            $result['validation'] = $validationRules;
        }

        // Generate type definitions based on format
        switch ($outputFormat) {
            case 'typescript':
                $result['typescript'] = $this->generateTypeScript($handle, $fieldTypes, $namespace, $generateInterfaces);
                break;
            case 'php':
                $result['php'] = $this->generatePHP($handle, $fieldTypes, $namespace, $generateInterfaces);
                break;
            case 'json-schema':
                $result['json_schema'] = $this->generateJsonSchema($handle, $fieldTypes, $blueprint->title());
                break;
            case 'all':
                $result['typescript'] = $this->generateTypeScript($handle, $fieldTypes, $namespace, $generateInterfaces);
                $result['php'] = $this->generatePHP($handle, $fieldTypes, $namespace, $generateInterfaces);
                $result['json_schema'] = $this->generateJsonSchema($handle, $fieldTypes, $blueprint->title());
                break;
        }

        return $result;
    }

    /**
     * Map Statamic field type to native type.
     */
    private function mapToNativeType(string $statamicType, string $outputFormat): string
    {
        $typeMap = match ($outputFormat) {
            'typescript' => [
                'text' => 'string',
                'textarea' => 'string',
                'markdown' => 'string',
                'bard' => 'string',
                'code' => 'string',
                'integer' => 'number',
                'float' => 'number',
                'toggle' => 'boolean',
                'date' => 'string',
                'time' => 'string',
                'select' => 'string',
                'radio' => 'string',
                'checkboxes' => 'string[]',
                'assets' => 'Asset[]',
                'entries' => 'Entry[]',
                'terms' => 'Term[]',
                'users' => 'User[]',
                'replicator' => 'ReplicatorSet[]',
                'grid' => 'GridRow[]',
                'group' => 'Record<string, any>',
                'link' => 'string',
                'color' => 'string',
                'range' => 'number',
                'slug' => 'string',
                'hidden' => 'string',
            ],
            'php' => [
                'text' => 'string',
                'textarea' => 'string',
                'markdown' => 'string',
                'bard' => 'string',
                'code' => 'string',
                'integer' => 'int',
                'float' => 'float',
                'toggle' => 'bool',
                'date' => 'string',
                'time' => 'string',
                'select' => 'string',
                'radio' => 'string',
                'checkboxes' => 'array',
                'assets' => 'array',
                'entries' => 'array',
                'terms' => 'array',
                'users' => 'array',
                'replicator' => 'array',
                'grid' => 'array',
                'group' => 'array',
                'link' => 'string',
                'color' => 'string',
                'range' => 'int',
                'slug' => 'string',
                'hidden' => 'string',
            ],
            default => [
                'text' => 'string',
                'integer' => 'number',
                'float' => 'number',
                'toggle' => 'boolean',
            ]
        };

        return $typeMap[$statamicType] ?? 'any';
    }

    /**
     * Check if field type represents a relationship.
     */
    private function isRelationshipField(string $fieldType): bool
    {
        return in_array($fieldType, ['entries', 'terms', 'users', 'assets']);
    }

    /**
     * Analyze relationship field configuration.
     *
     * @param  array<string, mixed>  $fieldConfig
     *
     * @return array<string, mixed>
     */
    private function analyzeRelationship(array $fieldConfig, string $fieldType): array
    {
        $relationship = [
            'type' => $fieldType,
            'multiple' => $fieldConfig['max_items'] !== 1,
        ];

        switch ($fieldType) {
            case 'entries':
                $relationship['collections'] = $fieldConfig['collections'] ?? [];
                break;
            case 'terms':
                $relationship['taxonomies'] = $fieldConfig['taxonomies'] ?? [];
                break;
            case 'assets':
                $relationship['container'] = $fieldConfig['container'] ?? null;
                break;
        }

        return $relationship;
    }

    /**
     * Generate TypeScript type definitions.
     *
     * @param  array<string, mixed>  $fieldTypes
     *
     * @return array<string, mixed>
     */
    private function generateTypeScript(string $handle, array $fieldTypes, ?string $namespace, bool $generateInterfaces): array
    {
        $className = Str::studly(str_replace('.', '_', $handle));
        $interfacePrefix = $namespace ? "{$namespace}." : '';

        $typeDefinition = $generateInterfaces ? "interface {$interfacePrefix}{$className} {\n" : "type {$interfacePrefix}{$className} = {\n";

        foreach ($fieldTypes as $fieldHandle => $fieldInfo) {
            $optional = $fieldInfo['nullable'] ? '?' : '';
            $typeDefinition .= "  {$fieldHandle}{$optional}: {$fieldInfo['native_type']};\n";
        }

        $typeDefinition .= $generateInterfaces ? '}' : '};';

        return [
            'definition' => $typeDefinition,
            'imports' => $this->getTypeScriptImports($fieldTypes),
            'filename' => Str::kebab($className) . '.ts',
        ];
    }

    /**
     * Generate PHP class definitions.
     *
     * @param  array<string, mixed>  $fieldTypes
     *
     * @return array<string, mixed>
     */
    private function generatePHP(string $handle, array $fieldTypes, ?string $namespace, bool $generateInterfaces): array
    {
        $className = Str::studly(str_replace('.', '_', $handle));
        $fullNamespace = $namespace ?: 'App\\Types';

        $classDefinition = "<?php\n\nnamespace {$fullNamespace};\n\n";
        $classDefinition .= $generateInterfaces ? "interface {$className}\n{\n" : "class {$className}\n{\n";

        if (! $generateInterfaces) {
            foreach ($fieldTypes as $fieldHandle => $fieldInfo) {
                $nullable = $fieldInfo['nullable'] ? '?' : '';
                $classDefinition .= "    public {$nullable}{$fieldInfo['native_type']} \${$fieldHandle};\n";
            }
        } else {
            foreach ($fieldTypes as $fieldHandle => $fieldInfo) {
                $nullable = $fieldInfo['nullable'] ? '?' : '';
                $classDefinition .= '    public function get' . Str::studly($fieldHandle) . "(): {$nullable}{$fieldInfo['native_type']};\n";
            }
        }

        $classDefinition .= '}';

        return [
            'definition' => $classDefinition,
            'namespace' => $fullNamespace,
            'classname' => $className,
            'filename' => $className . '.php',
        ];
    }

    /**
     * Generate JSON Schema definitions.
     *
     * @param  array<string, mixed>  $fieldTypes
     *
     * @return array<string, mixed>
     */
    private function generateJsonSchema(string $handle, array $fieldTypes, string $title): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => "https://example.com/{$handle}.schema.json",
            'title' => $title,
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        foreach ($fieldTypes as $fieldHandle => $fieldInfo) {
            $schema['properties'][$fieldHandle] = [
                'type' => $this->mapToJsonSchemaType($fieldInfo['statamic_type']),
                'title' => $fieldInfo['display'],
            ];

            if ($fieldInfo['instructions']) {
                $schema['properties'][$fieldHandle]['description'] = $fieldInfo['instructions'];
            }

            if (! $fieldInfo['nullable']) {
                $schema['required'][] = $fieldHandle;
            }
        }

        return $schema;
    }

    /**
     * Map to JSON Schema types.
     */
    private function mapToJsonSchemaType(string $statamicType): string
    {
        return match ($statamicType) {
            'text', 'textarea', 'markdown', 'bard', 'code', 'date', 'time', 'select', 'radio', 'link', 'color', 'slug', 'hidden' => 'string',
            'integer', 'range' => 'integer',
            'float' => 'number',
            'toggle' => 'boolean',
            'checkboxes', 'assets', 'entries', 'terms', 'users', 'replicator', 'grid' => 'array',
            'group' => 'object',
            default => 'string',
        };
    }

    /**
     * Get TypeScript imports needed.
     *
     * @param  array<string, mixed>  $fieldTypes
     *
     * @return array<int, string>
     */
    private function getTypeScriptImports(array $fieldTypes): array
    {
        $imports = [];

        foreach ($fieldTypes as $fieldInfo) {
            if (in_array($fieldInfo['native_type'], ['Asset[]', 'Entry[]', 'Term[]', 'User[]', 'ReplicatorSet[]', 'GridRow[]'])) {
                $baseType = str_replace('[]', '', $fieldInfo['native_type']);
                $imports[] = $baseType;
            }
        }

        return array_unique($imports);
    }

    /**
     * Generate summary of type analysis.
     *
     * @param  array<string, mixed>  $typeDefinitions
     */
    private function generateSummary(array $typeDefinitions, string $outputFormat): string
    {
        $totalFields = array_sum(array_column($typeDefinitions, 'field_count'));
        $blueprintCount = count($typeDefinitions);

        return "Analyzed {$blueprintCount} blueprints with {$totalFields} total fields. Generated {$outputFormat} type definitions.";
    }
}
