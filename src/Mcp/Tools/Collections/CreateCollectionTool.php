<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Collections;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

#[Title('Create Statamic Collection')]
class CreateCollectionTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.collections.create';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Create a new Statamic collection with optional configuration and default blueprint';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Collection handle/identifier (lowercase, no spaces)')
            ->required()
            ->string('title')
            ->description('Human-readable collection title')
            ->required()
            ->string('route')
            ->description('Collection route pattern (e.g., /blog/{slug})')
            ->optional()
            ->string('layout')
            ->description('Default layout template')
            ->optional()
            ->string('template')
            ->description('Default entry template')
            ->optional()
            ->boolean('dated')
            ->description('Whether entries should be dated')
            ->optional()
            ->boolean('structured')
            ->description('Whether collection should be structured (nested entries)')
            ->optional()
            ->integer('max_depth')
            ->description('Maximum nesting depth for structured collections')
            ->optional()
            ->raw('sites', ['type' => 'array', 'items' => ['type' => 'string']])
            ->description('Sites where this collection is available')
            ->optional()
            ->raw('taxonomies', ['type' => 'array', 'items' => ['type' => 'string']])
            ->description('Taxonomies associated with this collection')
            ->optional()
            ->boolean('create_default_blueprint')
            ->description('Create a default blueprint with title and content fields')
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
        $createDefaultBlueprint = $arguments['create_default_blueprint'] ?? true;

        try {
            if (Collection::find($handle)) {
                return [
                    'error' => "Collection '{$handle}' already exists",
                    'handle' => $handle,
                    'suggestion' => 'Use statamic.collections.update to modify existing collection or choose a different handle',
                ];
            }

            $collection = Collection::make($handle)->title($title);

            if (isset($arguments['route'])) {
                $collection->route($arguments['route']);
            }

            if (isset($arguments['layout'])) {
                $collection->layout($arguments['layout']);
            }

            if (isset($arguments['template'])) {
                $collection->template($arguments['template']);
            }

            if (isset($arguments['dated'])) {
                $collection->dated($arguments['dated']);
            }

            if (isset($arguments['structured']) && $arguments['structured']) {
                $collection->structure([
                    'max_depth' => $arguments['max_depth'] ?? 3,
                ]);
            }

            if (isset($arguments['sites'])) {
                $collection->sites($arguments['sites']);
            }

            if (isset($arguments['taxonomies'])) {
                $collection->taxonomies($arguments['taxonomies']);
            }

            $collection->save();

            $blueprintCreated = false;
            if ($createDefaultBlueprint) {
                try {
                    $blueprint = Blueprint::make($handle)
                        ->setNamespace('collections.' . $handle)
                        ->setContents([
                            'title' => $title,
                            'sections' => [
                                'main' => [
                                    'display' => 'Main',
                                    'fields' => [
                                        [
                                            'handle' => 'title',
                                            'field' => [
                                                'type' => 'text',
                                                'required' => true,
                                            ],
                                        ],
                                        [
                                            'handle' => 'content',
                                            'field' => [
                                                'type' => 'markdown',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ]);
                    $blueprint->save();
                    $blueprintCreated = true;
                } catch (\Exception $e) {
                }
            }

            $cacheResult = $this->clearStatamicCaches($this->getRecommendedCacheTypes('collection_change'));

            return [
                'handle' => $handle,
                'title' => $title,
                'dated' => $collection->dated(),
                'structured' => $collection->hasStructure(),
                'blueprint_created' => $blueprintCreated,
                'cache_cleared' => $cacheResult['cache_cleared'] ?? false,
                'message' => "Collection '{$handle}' created successfully",
            ];
        } catch (\Exception $e) {
            return [
                'error' => "Failed to create collection '{$handle}': " . $e->getMessage(),
                'handle' => $handle,
                'title' => $title,
            ];
        }
    }
}
