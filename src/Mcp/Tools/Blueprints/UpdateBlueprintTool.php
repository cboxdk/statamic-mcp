<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Blueprints;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;

#[Title('Update Statamic Blueprint')]
class UpdateBlueprintTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.blueprints.update';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Update an existing blueprint with new field definitions or configuration';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Blueprint handle/identifier')
            ->required()
            ->string('namespace')
            ->description('Blueprint namespace (collections, forms, taxonomies, globals, assets, users, navs)')
            ->optional()
            ->string('title')
            ->description('Update blueprint title')
            ->optional()
            ->raw('sections', [
                'type' => 'object',
                'description' => 'Blueprint sections with field definitions (replaces all existing sections)',
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
            ->optional()
            ->boolean('merge_sections')
            ->description('Merge new sections with existing ones instead of replacing all')
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
        $namespace = $arguments['namespace'] ?? 'collections';
        $title = $arguments['title'] ?? null;
        /** @var array<string, array<string, mixed>>|null $sections */
        $sections = $arguments['sections'] ?? null;
        /** @var array<string>|null $hidden */
        $hidden = $arguments['hidden'] ?? null;
        $order = $arguments['order'] ?? null;
        $mergeSections = $arguments['merge_sections'] ?? false;

        try {
            // Find existing blueprint
            $blueprint = Blueprint::find("{$namespace}.{$handle}");
            if (! $blueprint) {
                return [
                    'error' => "Blueprint '{$handle}' not found in namespace '{$namespace}'",
                    'handle' => $handle,
                    'namespace' => $namespace,
                    'suggestion' => 'Use statamic.blueprints.create to create a new blueprint',
                ];
            }

            // Get current contents
            /** @var array<string, mixed> $currentContents */
            $currentContents = $blueprint->contents();
            $updatedContents = $currentContents;

            // Update title if provided
            if ($title !== null) {
                $updatedContents['title'] = $title;
            }

            // Update sections if provided
            if ($sections !== null) {
                if ($mergeSections && isset($currentContents['sections'])) {
                    // Merge with existing sections
                    /** @var array<string, array<string, mixed>> $existingSections */
                    $existingSections = $currentContents['sections'];
                    $updatedContents['sections'] = array_merge($existingSections, $sections);
                } else {
                    // Replace all sections
                    $updatedContents['sections'] = $sections;
                }
            }

            // Update hidden fields if provided
            if ($hidden !== null) {
                if (empty($hidden)) {
                    unset($updatedContents['hidden']);
                } else {
                    $updatedContents['hidden'] = $hidden;
                }
            }

            // Update order if provided
            if ($order !== null) {
                $updatedContents['order'] = $order;
            }

            // Check if anything actually changed
            if ($updatedContents === $currentContents) {
                return [
                    'handle' => $handle,
                    'namespace' => $namespace,
                    'title' => $blueprint->title(),
                    'message' => 'No changes detected - blueprint remains unchanged',
                    'changed' => false,
                ];
            }

            // Update the blueprint
            $blueprint->setContents($updatedContents);
            $blueprint->save();

            // Clear relevant caches
            $cacheResult = $this->clearStatamicCaches($this->getRecommendedCacheTypes('blueprint_change'));

            // Count fields for summary
            /** @var array<string, array<string, mixed>> $finalSections */
            $finalSections = $updatedContents['sections'] ?? [];
            $totalFields = collect($finalSections)->sum(fn (array $section): int => count($section['fields'] ?? []));

            return [
                'handle' => $handle,
                'namespace' => $namespace,
                'title' => $updatedContents['title'] ?? $blueprint->title(),
                'sections_count' => count($finalSections),
                'total_fields' => $totalFields,
                'cache_cleared' => $cacheResult['cache_cleared'] ?? false,
                'changed' => true,
                'message' => "Blueprint '{$handle}' updated successfully in namespace '{$namespace}'",
                'merge_mode' => $mergeSections,
            ];
        } catch (\Exception $e) {
            return [
                'error' => "Failed to update blueprint '{$handle}' in namespace '{$namespace}': " . $e->getMessage(),
                'handle' => $handle,
                'namespace' => $namespace,
            ];
        }
    }
}
