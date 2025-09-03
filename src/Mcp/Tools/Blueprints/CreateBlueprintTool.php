<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Blueprints;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;

#[Title('Create Statamic Blueprint')]
class CreateBlueprintTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.blueprints.create';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Create a new blueprint with field definitions from user input - no hardcoded templates';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Blueprint handle/identifier (lowercase, no spaces)')
            ->required()
            ->string('title')
            ->description('Human-readable title for the blueprint')
            ->required()
            ->string('namespace')
            ->description('Blueprint namespace (collections, forms, taxonomies, globals, assets, users, navs)')
            ->optional()
            ->raw('tabs', [
                'type' => 'object',
                'description' => 'Blueprint tabs structure (Statamic v5+ format)',
                'additionalProperties' => [
                    'type' => 'object',
                    'properties' => [
                        'display' => ['type' => 'string'],
                        'sections' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'display' => ['type' => 'string'],
                                    'instructions' => ['type' => 'string'],
                                    'fields' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'handle' => ['type' => 'string'],
                                                'field' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'type' => ['type' => 'string'],
                                                        'display' => ['type' => 'string'],
                                                        'required' => ['type' => 'boolean'],
                                                    ],
                                                ],
                                            ],
                                            'required' => ['handle', 'field'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->required()
            ->raw('sections', [
                'type' => 'object',
                'description' => 'Legacy sections format (v4 compatibility)',
                'additionalProperties' => [
                    'type' => 'object',
                    'properties' => [
                        'display' => ['type' => 'string'],
                        'instructions' => ['type' => 'string'],
                        'fields' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'handle' => ['type' => 'string'],
                                    'field' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'type' => ['type' => 'string'],
                                            'display' => ['type' => 'string'],
                                            'required' => ['type' => 'boolean'],
                                        ],
                                    ],
                                ],
                                'required' => ['handle', 'field'],
                            ],
                        ],
                    ],
                ],
            ])
            ->optional()
            ->raw('hidden', ['type' => 'array', 'items' => ['type' => 'string']])
            ->description('Fields to hide from forms')
            ->optional()
            ->integer('order')
            ->description('Blueprint order/priority')
            ->optional();
    }

    /**
     * Execute the tool.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $handle = $arguments['handle'];
        $title = $arguments['title'];
        $namespace = $arguments['namespace'] ?? 'collections';
        /** @var array<string, array<string, mixed>> $tabs */
        $tabs = $arguments['tabs'] ?? [];
        /** @var array<string, array<string, mixed>> $sections */
        $sections = $arguments['sections'] ?? [];
        /** @var array<string> $hidden */
        $hidden = $arguments['hidden'] ?? [];
        $order = $arguments['order'] ?? null;

        try {
            // Check if blueprint already exists
            $existingBlueprint = Blueprint::find("{$namespace}.{$handle}");
            if ($existingBlueprint) {
                return [
                    'error' => "Blueprint '{$handle}' already exists in namespace '{$namespace}'",
                    'handle' => $handle,
                    'namespace' => $namespace,
                    'suggestion' => 'Use statamic.blueprints.update to modify existing blueprint or choose a different handle',
                ];
            }

            // Validate structure - prefer tabs format
            if (empty($tabs) && empty($sections)) {
                return [
                    'error' => 'Blueprint must have either tabs (v5+ format) or sections (legacy format) with fields',
                    'handle' => $handle,
                    'namespace' => $namespace,
                ];
            }

            // Prepare blueprint contents using tabs format (v5+)
            $blueprintContents = [
                'title' => $title,
            ];

            if (! empty($tabs)) {
                $blueprintContents['tabs'] = $tabs;
            } else {
                // Legacy format fallback
                $blueprintContents['sections'] = $sections;
            }

            if (! empty($hidden)) {
                $blueprintContents['hidden'] = $hidden;
            }

            if ($order !== null) {
                $blueprintContents['order'] = $order;
            }

            // Create the blueprint
            $blueprint = Blueprint::make()
                ->setNamespace($namespace)
                ->setHandle($handle)
                ->setContents($blueprintContents);

            $blueprint->save();

            // Clear relevant caches
            $cacheResult = $this->clearStatamicCaches($this->getRecommendedCacheTypes('blueprint_change'));

            // Count fields for summary
            $totalFields = 0;
            if (! empty($tabs)) {
                foreach ($tabs as $tab) {
                    if (isset($tab['sections']) && is_array($tab['sections'])) {
                        $totalFields += collect($tab['sections'])->sum(fn (array $section): int => count($section['fields'] ?? []));
                    }
                }
            } else {
                $totalFields = collect($sections)->sum(fn (array $section): int => count($section['fields'] ?? []));
            }

            return [
                'handle' => $handle,
                'namespace' => $namespace,
                'title' => $title,
                'tabs_count' => count($tabs),
                'sections_count' => ! empty($tabs) ?
                    collect($tabs)->sum(fn ($tab) => count($tab['sections'] ?? [])) :
                    count($sections),
                'total_fields' => $totalFields,
                'format' => ! empty($tabs) ? 'v5_tabs' : 'legacy_sections',
                'cache_cleared' => $cacheResult['cache_cleared'] ?? false,
                'message' => "Blueprint '{$handle}' created successfully in namespace '{$namespace}'",
            ];
        } catch (\Exception $e) {
            return [
                'error' => "Failed to create blueprint '{$handle}' in namespace '{$namespace}': " . $e->getMessage(),
                'handle' => $handle,
                'namespace' => $namespace,
                'title' => $title,
            ];
        }
    }
}
