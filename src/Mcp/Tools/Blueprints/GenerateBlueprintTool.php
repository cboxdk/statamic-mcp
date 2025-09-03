<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Blueprints;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Cboxdk\StatamicMcp\Mcp\Tools\Factories\DynamicFieldTemplateFactory;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Symfony\Component\Yaml\Yaml;

#[Title('Generate Statamic Blueprints')]
class GenerateBlueprintTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    private DynamicFieldTemplateFactory $templateFactory;

    public function __construct()
    {
        $this->templateFactory = new DynamicFieldTemplateFactory;
    }

    protected function getToolName(): string
    {
        return 'statamic.blueprints.generate';
    }

    protected function getToolDescription(): string
    {
        return 'Generate complex blueprint and fieldset structures with scaffolding for collections, taxonomies, and complete content architectures';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema = $this->addCacheSchema($schema)
            ->string('name')
            ->description('Name of the structure (e.g., "blog", "products")')
            ->required()
            ->string('title')
            ->description('Human-readable title for the structure')
            ->optional()
            ->raw('fields', [
                'type' => 'array',
                'description' => 'Field definitions for the blueprint (LLM-defined structure)',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => ['type' => 'string'],
                        'field' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string'],
                                'required' => ['type' => 'boolean'],
                            ],
                            'additionalProperties' => true,
                        ],
                    ],
                    'required' => ['handle', 'field'],
                ],
            ])
            ->required()
            ->boolean('get_suggestions')
            ->description('Get suggestions based on existing patterns instead of generating blueprint')
            ->optional()
            ->boolean('create_views')
            ->description('Generate corresponding view templates')
            ->optional()
            ->boolean('create_routes')
            ->description('Generate route definitions')
            ->optional()
            ->boolean('create_fieldsets')
            ->description('Create reusable fieldsets for common field groups')
            ->optional()
            ->boolean('scaffold_complete')
            ->description('Generate complete structure including controllers, views, and routes')
            ->optional();

        return $this->addTypeSchema($schema,
            ['collection', 'taxonomy', 'global', 'fieldset', 'structure'],
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $getSuggestions = $arguments['get_suggestions'] ?? false;

        if ($getSuggestions) {
            return [
                'suggestions' => $this->templateFactory->getSuggestions(),
                'valid_field_types' => $this->getValidFieldTypes(),
                'blueprint_examples' => $this->getExistingBlueprintExamples(),
            ];
        }

        $type = $arguments['type'];
        $name = $arguments['name'];
        $title = $arguments['title'] ?? $this->generateTitle($name);
        $llmFields = $arguments['fields'] ?? [];

        $validatedFields = $this->templateFactory->validateAndProcessFields($llmFields);

        if (empty($validatedFields)) {
            throw new \InvalidArgumentException('No valid fields provided. Use get_suggestions=true to see examples.');
        }

        try {
            $result = match ($type) {
                'collection' => $this->generateCollection($name, $title, $validatedFields),
                'taxonomy' => $this->generateTaxonomy($name, $title, $validatedFields),
                'global' => $this->generateGlobal($name, $title, $validatedFields),
                'fieldset' => $this->generateFieldset($name, $title, $validatedFields),
                default => throw new \InvalidArgumentException("Unknown type: {$type}")
            };

            $operationType = match ($type) {
                'collection' => 'collection_change',
                'taxonomy' => 'taxonomy_change',
                'global' => 'global_change',
                'fieldset' => 'fieldset_change',
                default => 'blueprint_change'
            };

            $cacheTypes = $this->getRecommendedCacheTypes($operationType);
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'type' => $type,
                'name' => $name,
                'title' => $title,
                'generated_files' => $result['files'],
                'structure' => $result['structure'],
                'cache' => $cacheResult,
                'summary' => $this->generateSummary($type, $name, $result['files']),
            ];

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $validatedFields  Field configurations indexed by handle
     *
     * @return array<string, mixed>
     */
    private function generateCollection(string $name, string $title, array $validatedFields): array
    {
        $blueprintData = [
            'title' => $title,
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => $this->convertFieldsToBlueprint($validatedFields),
                        ],
                    ],
                ],
            ],
        ];

        $blueprintPath = resource_path("blueprints/collections/{$name}/{$name}.yaml");
        $collectionPath = resource_path("collections/{$name}.yaml");

        $collectionData = [
            'title' => $title,
            'blueprints' => [$name],
            'route' => '/' . $name . '/{slug}',
        ];

        return [
            'files' => [
                [
                    'path' => $blueprintPath,
                    'content' => Yaml::dump($blueprintData, 4, 2),
                    'type' => 'blueprint',
                ],
                [
                    'path' => $collectionPath,
                    'content' => Yaml::dump($collectionData, 4, 2),
                    'type' => 'collection',
                ],
            ],
            'structure' => [
                'type' => 'collection',
                'handle' => $name,
                'blueprint' => $blueprintData,
                'collection' => $collectionData,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validatedFields  Field configurations indexed by handle
     *
     * @return array<string, mixed>
     */
    private function generateTaxonomy(string $name, string $title, array $validatedFields): array
    {
        $blueprintFields = $this->convertFieldsToBlueprint($validatedFields);

        $blueprintData = [
            'title' => $title,
            'sections' => [
                [
                    'fields' => $blueprintFields,
                ],
            ],
        ];

        $blueprintPath = resource_path("blueprints/taxonomies/{$name}/{$name}.yaml");
        $taxonomyPath = resource_path("taxonomies/{$name}.yaml");

        $taxonomyData = [
            'title' => $title,
            'blueprints' => [$name],
        ];

        return [
            'files' => [
                [
                    'path' => $blueprintPath,
                    'content' => Yaml::dump($blueprintData, 4, 2),
                    'type' => 'blueprint',
                ],
                [
                    'path' => $taxonomyPath,
                    'content' => Yaml::dump($taxonomyData, 4, 2),
                    'type' => 'taxonomy',
                ],
            ],
            'structure' => [
                'type' => 'taxonomy',
                'handle' => $name,
                'blueprint' => $blueprintData,
                'taxonomy' => $taxonomyData,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validatedFields  Field configurations indexed by handle
     *
     * @return array<string, mixed>
     */
    private function generateGlobal(string $name, string $title, array $validatedFields): array
    {
        $blueprintFields = $this->convertFieldsToBlueprint($validatedFields);

        $blueprintData = [
            'title' => $title,
            'sections' => [
                [
                    'fields' => $blueprintFields,
                ],
            ],
        ];

        $blueprintPath = resource_path("blueprints/globals/{$name}.yaml");
        $globalPath = resource_path("globals/{$name}.yaml");

        $globalData = [
            'title' => $title,
            'data' => [],
        ];

        return [
            'files' => [
                [
                    'path' => $blueprintPath,
                    'content' => Yaml::dump($blueprintData, 4, 2),
                    'type' => 'blueprint',
                ],
                [
                    'path' => $globalPath,
                    'content' => Yaml::dump($globalData, 4, 2),
                    'type' => 'global',
                ],
            ],
            'structure' => [
                'type' => 'global',
                'handle' => $name,
                'blueprint' => $blueprintData,
                'global' => $globalData,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validatedFields  Field configurations indexed by handle
     *
     * @return array<string, mixed>
     */
    private function generateFieldset(string $name, string $title, array $validatedFields): array
    {
        $fieldsetFields = $validatedFields;

        $fieldset = [
            'title' => $title,
            'fields' => $fieldsetFields,
        ];

        $fieldsetPath = resource_path("fieldsets/{$name}.yaml");

        return [
            'files' => [
                [
                    'path' => $fieldsetPath,
                    'content' => Yaml::dump($fieldset, 4, 2),
                    'type' => 'fieldset',
                ],
            ],
            'structure' => [
                'type' => 'fieldset',
                'handle' => $name,
                'fieldset' => $fieldset,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @return array<int, array<string, mixed>>
     */
    private function convertFieldsToBlueprint(array $fields): array
    {
        $blueprintFields = [];

        foreach ($fields as $field) {
            $blueprintFields[] = [
                'handle' => $field['handle'],
                'field' => $field['field'],
            ];
        }

        return $blueprintFields;
    }

    private function generateTitle(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name));
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    private function generateSummary(string $type, string $name, array $files): string
    {
        $count = count($files);
        $types = array_unique(array_column($files, 'type'));

        return "Generated {$count} files for {$type} '{$name}': " . implode(', ', $types);
    }

    /**
     * @return array<string, string>
     */
    private function getValidFieldTypes(): array
    {
        $fieldTypes = ['text', 'textarea', 'markdown', 'bard', 'code',
            'integer', 'float', 'toggle', 'select', 'radio', 'checkboxes',
            'date', 'time', 'range', 'color', 'link',
            'assets', 'entries', 'terms', 'users',
            'replicator', 'grid', 'group', 'import',
            'section', 'html', 'hidden', 'slug',
            'template', 'revealer', 'spacer',
            'taxonomy', 'collection', ];

        $result = [];
        foreach ($fieldTypes as $type) {
            $result[$type] = $type;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getExistingBlueprintExamples(): array
    {
        $examples = [];

        try {
            // Get blueprints from resource path instead of using undefined method
            $blueprintPaths = [
                resource_path('blueprints/collections'),
                resource_path('blueprints/taxonomies'),
                resource_path('blueprints/globals'),
            ];

            $count = 0;
            foreach ($blueprintPaths as $path) {
                if (! file_exists($path)) {
                    continue;
                }

                $files = glob($path . '/**/*.yaml') ?: [];
                foreach ($files as $file) {
                    if ($count >= 3) {
                        break 2;
                    }

                    $handle = basename($file, '.yaml');
                    $examples[] = [
                        'handle' => $handle,
                        'title' => ucfirst(str_replace('_', ' ', $handle)),
                        'field_count' => 0,
                        'field_types' => [],
                    ];
                    $count++;
                }
            }
        } catch (\Exception $e) {
            // Return empty if error
        }

        return $examples;
    }
}
