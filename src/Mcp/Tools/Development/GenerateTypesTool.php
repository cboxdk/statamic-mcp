<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

#[Title('Generate Types from Blueprints')]
class GenerateTypesTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.development.types.generate';
    }

    protected function getToolDescription(): string
    {
        return 'Generate TypeScript, PHP, or JSON type definitions from Statamic blueprints';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->raw('format', [
                'type' => 'string',
                'description' => 'Output format for type definitions',
                'enum' => ['typescript', 'php', 'json'],
            ])
            ->required()
            ->raw('collections', [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Collection handles to generate types for',
            ])
            ->description('Collection handles to generate types for (optional, defaults to all)')
            ->optional()
            ->raw('blueprints', [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Specific blueprint handles to generate types for (optional)',
            ])
            ->optional()
            ->boolean('include_fieldsets')
            ->description('Include fieldset-based types (default: true)')
            ->optional()
            ->boolean('export_interfaces')
            ->description('Export as interfaces/types instead of classes (default: true for TS)')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        try {
            $format = $arguments['format'];
            $collections = $arguments['collections'] ?? null;
            $blueprints = $arguments['blueprints'] ?? null;
            $includeFieldsets = $arguments['include_fieldsets'] ?? true;
            $exportInterfaces = $arguments['export_interfaces'] ?? ($format === 'typescript');

            // Get blueprints to process
            $blueprintsToProcess = $this->getBlueprintsToProcess($collections, $blueprints);

            if (empty($blueprintsToProcess)) {
                return $this->createErrorResponse('No blueprints found to process')->toArray();
            }

            // Generate types based on format
            $generatedTypes = match ($format) {
                'typescript' => $this->generateTypeScriptTypes($blueprintsToProcess, $exportInterfaces),
                'php' => $this->generatePhpTypes($blueprintsToProcess, $exportInterfaces),
                'json' => $this->generateJsonTypes($blueprintsToProcess),
                default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
            };

            return [
                'format' => $format,
                'blueprints_processed' => count($blueprintsToProcess),
                'generated_types' => $generatedTypes,
                'export_interfaces' => $exportInterfaces,
                'include_fieldsets' => $includeFieldsets,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to generate types: ' . $e->getMessage(), [
                'exception_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ])->toArray();
        }
    }

    /**
     * Get blueprints to process based on filters.
     *
     * @param  array<string>|null  $collections
     * @param  array<string>|null  $blueprints
     *
     * @return array<string, \Statamic\Fields\Blueprint>
     */
    private function getBlueprintsToProcess(?array $collections, ?array $blueprints): array
    {
        $allBlueprints = [];

        if ($blueprints) {
            // Get specific blueprints by handle
            foreach ($blueprints as $blueprintHandle) {
                try {
                    // Try different namespace lookups
                    $blueprint = Blueprint::find("collections.{$blueprintHandle}")
                        ?? Blueprint::find($blueprintHandle);

                    if ($blueprint) {
                        $allBlueprints[$blueprintHandle] = $blueprint;
                    }
                } catch (\Exception $e) {
                    // Skip invalid blueprints
                    continue;
                }
            }
        } else {
            // Get all available blueprints from multiple namespaces
            try {
                // Get collection blueprints
                if ($collections) {
                    foreach ($collections as $collectionHandle) {
                        $collection = Collection::find($collectionHandle);
                        if ($collection && $collection->entryBlueprints()) {
                            foreach ($collection->entryBlueprints() as $blueprint) {
                                if ($blueprint) {
                                    $allBlueprints[$blueprint->handle()] = $blueprint;
                                }
                            }
                        }
                    }
                } else {
                    // Get all blueprints from collections namespace
                    $blueprintFiles = glob(resource_path('blueprints/collections/*.yaml'));

                    if (is_array($blueprintFiles)) {
                        foreach ($blueprintFiles as $file) {
                            $handle = basename($file, '.yaml');
                            $blueprint = Blueprint::find("collections.{$handle}");
                            if ($blueprint) {
                                $allBlueprints[$handle] = $blueprint;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Fallback: return empty array if blueprint discovery fails
                return [];
            }
        }

        return $allBlueprints;
    }

    /**
     * Generate TypeScript type definitions.
     *
     * @param  array<string, \Statamic\Fields\Blueprint>  $blueprints
     *
     * @return array<string, string>
     */
    private function generateTypeScriptTypes(array $blueprints, bool $exportInterfaces): array
    {
        $types = [];

        foreach ($blueprints as $handle => $blueprint) {
            try {
                $interfaceName = $this->toPascalCase($handle);
                $keyword = $exportInterfaces ? 'export interface' : 'interface';

                $typeDefinition = "{$keyword} {$interfaceName} {\n";

                // Get blueprint fields
                $blueprintFields = $blueprint->fields();
                $fields = $blueprintFields->all();

                foreach ($fields as $fieldHandle => $field) {
                    try {
                        $fieldType = 'string'; // default
                        if (is_object($field) && method_exists($field, 'type')) {
                            $fieldType = $field->type();
                        } elseif (is_array($field) && isset($field['type'])) {
                            $fieldType = $field['type'];
                        }

                        $tsType = $this->mapFieldTypeToTypeScript($fieldType);

                        $required = false;
                        if (is_object($field) && method_exists($field, 'get')) {
                            $required = $field->get('required', false);
                        } elseif (is_array($field)) {
                            $required = $field['required'] ?? false;
                        }

                        $optional = $required ? '' : '?';
                        $typeDefinition .= "  {$fieldHandle}{$optional}: {$tsType};\n";
                    } catch (\Exception $e) {
                        // Skip problematic fields, use fallback type
                        $typeDefinition .= "  {$fieldHandle}?: any; // Error: {$e->getMessage()}\n";
                    }
                }

                $typeDefinition .= '}';
                $types[$handle] = $typeDefinition;

            } catch (\Exception $e) {
                // Add error information to types
                $types[$handle] = "// Error generating type for {$handle}: " . $e->getMessage();
            }
        }

        return $types;
    }

    /**
     * Generate PHP type definitions.
     *
     * @param  array<string, \Statamic\Fields\Blueprint>  $blueprints
     *
     * @return array<string, string>
     */
    private function generatePhpTypes(array $blueprints, bool $exportInterfaces): array
    {
        $types = [];

        foreach ($blueprints as $handle => $blueprint) {
            $className = $this->toPascalCase($handle);

            if ($exportInterfaces) {
                $typeDefinition = "<?php\n\ninterface {$className}\n{\n";

                foreach ($blueprint->fields()->all() as $fieldHandle => $field) {
                    $phpType = $this->mapFieldTypeToPHP($field->type());
                    $methodName = $this->toCamelCase($fieldHandle);
                    $typeDefinition .= "    public function {$methodName}(): {$phpType};\n";
                    $typeDefinition .= "    public function set{$this->toPascalCase($fieldHandle)}({$phpType} \$value): void;\n";
                }
            } else {
                $typeDefinition = "<?php\n\nclass {$className}\n{\n";

                foreach ($blueprint->fields()->all() as $fieldHandle => $field) {
                    $phpType = $this->mapFieldTypeToPHP($field->type());
                    $typeDefinition .= "    private {$phpType} \${$fieldHandle};\n";
                }

                $typeDefinition .= "\n    // Getters and setters...\n";
            }

            $typeDefinition .= '}';
            $types[$handle] = $typeDefinition;
        }

        return $types;
    }

    /**
     * Generate JSON schema definitions.
     *
     * @param  array<string, \Statamic\Fields\Blueprint>  $blueprints
     *
     * @return array<string, array<string, mixed>>
     */
    private function generateJsonTypes(array $blueprints): array
    {
        $schemas = [];

        foreach ($blueprints as $handle => $blueprint) {
            $schema = [
                'type' => 'object',
                'title' => $blueprint->title() ?? $this->toPascalCase($handle),
                'properties' => [],
                'required' => [],
            ];

            foreach ($blueprint->fields()->all() as $fieldHandle => $field) {
                $schema['properties'][$fieldHandle] = $this->mapFieldTypeToJsonSchema($field);

                if ($field->get('required', false)) {
                    $schema['required'][] = $fieldHandle;
                }
            }

            $schemas[$handle] = $schema;
        }

        return $schemas;
    }

    private function mapFieldTypeToTypeScript(string $fieldType): string
    {
        return match ($fieldType) {
            'text', 'textarea', 'markdown', 'code', 'slug', 'template', 'color' => 'string',
            'integer', 'range', 'float' => 'number',
            'toggle', 'checkbox' => 'boolean',
            'date', 'time' => 'string',
            'entries', 'terms', 'assets', 'users' => 'string[]',
            'select', 'radio', 'button_group' => 'string',
            'checkboxes' => 'string[]',
            'yaml', 'array', 'grid', 'replicator' => 'any[]',
            'bard' => 'any',
            default => 'any',
        };
    }

    private function mapFieldTypeToPHP(string $fieldType): string
    {
        return match ($fieldType) {
            'text', 'textarea', 'markdown', 'code', 'slug', 'template', 'color' => 'string',
            'integer', 'range' => 'int',
            'float' => 'float',
            'toggle', 'checkbox' => 'bool',
            'date', 'time' => 'string',
            'entries', 'terms', 'assets', 'users', 'checkboxes' => 'array',
            'select', 'radio', 'button_group' => 'string',
            'yaml', 'array', 'grid', 'replicator' => 'array',
            'bard' => 'array',
            default => 'mixed',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mapFieldTypeToJsonSchema(\Statamic\Fields\Field $field): array
    {
        $type = $field->type();

        return match ($type) {
            'text', 'textarea', 'markdown', 'code', 'slug', 'template', 'color' => [
                'type' => 'string',
                'description' => $field->get('instructions'),
            ],
            'integer', 'range' => [
                'type' => 'integer',
                'description' => $field->get('instructions'),
            ],
            'float' => [
                'type' => 'number',
                'description' => $field->get('instructions'),
            ],
            'toggle', 'checkbox' => [
                'type' => 'boolean',
                'description' => $field->get('instructions'),
            ],
            'entries', 'terms', 'assets', 'users', 'checkboxes' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => $field->get('instructions'),
            ],
            'select', 'radio', 'button_group' => [
                'type' => 'string',
                'enum' => array_keys($field->get('options', [])),
                'description' => $field->get('instructions'),
            ],
            default => [
                'type' => 'object',
                'description' => $field->get('instructions'),
            ],
        };
    }

    private function toPascalCase(string $string): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $string)));
    }

    private function toCamelCase(string $string): string
    {
        $pascalCase = $this->toPascalCase($string);

        return lcfirst($pascalCase);
    }
}
