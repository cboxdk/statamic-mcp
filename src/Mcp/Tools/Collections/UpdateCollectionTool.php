<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Collections;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Collection;

#[Title('Update Statamic Collection')]
class UpdateCollectionTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.collections.update';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Update an existing Statamic collection configuration';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Collection handle/identifier')
            ->required()
            ->string('title')
            ->description('Human-readable collection title')
            ->optional()
            ->string('route')
            ->description('Collection route pattern')
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
            ->description('Whether collection should be structured')
            ->optional()
            ->integer('max_depth')
            ->description('Maximum nesting depth for structured collections')
            ->optional()
            ->raw('sites', ['type' => 'array', 'items' => ['type' => 'string']])
            ->description('Sites where this collection is available')
            ->optional()
            ->raw('taxonomies', ['type' => 'array', 'items' => ['type' => 'string']])
            ->description('Taxonomies associated with this collection')
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

        try {
            $collection = Collection::find($handle);

            if (! $collection) {
                return [
                    'error' => "Collection '{$handle}' not found",
                    'handle' => $handle,
                ];
            }

            $changed = false;
            $changes = [];

            if (isset($arguments['title'])) {
                $collection->title($arguments['title']);
                $changes[] = 'title';
                $changed = true;
            }

            if (isset($arguments['route'])) {
                $collection->route($arguments['route']);
                $changes[] = 'route';
                $changed = true;
            }

            if (isset($arguments['layout'])) {
                $collection->layout($arguments['layout']);
                $changes[] = 'layout';
                $changed = true;
            }

            if (isset($arguments['template'])) {
                $collection->template($arguments['template']);
                $changes[] = 'template';
                $changed = true;
            }

            if (isset($arguments['dated'])) {
                $collection->dated($arguments['dated']);
                $changes[] = 'dated';
                $changed = true;
            }

            if (isset($arguments['structured'])) {
                if ($arguments['structured']) {
                    $collection->structure([
                        'max_depth' => $arguments['max_depth'] ?? 3,
                    ]);
                } else {
                    $collection->structure(null);
                }
                $changes[] = 'structure';
                $changed = true;
            }

            if (isset($arguments['sites'])) {
                $collection->sites($arguments['sites']);
                $changes[] = 'sites';
                $changed = true;
            }

            if (isset($arguments['taxonomies'])) {
                $collection->taxonomies($arguments['taxonomies']);
                $changes[] = 'taxonomies';
                $changed = true;
            }

            if (! $changed) {
                return [
                    'handle' => $handle,
                    'title' => $collection->title(),
                    'message' => 'No changes detected - collection remains unchanged',
                    'changed' => false,
                ];
            }

            $collection->save();

            $cacheResult = $this->clearStatamicCaches($this->getRecommendedCacheTypes('collection_change'));

            return [
                'handle' => $handle,
                'title' => $collection->title(),
                'changes' => $changes,
                'changed' => true,
                'cache_cleared' => $cacheResult['cache_cleared'] ?? false,
                'message' => "Collection '{$handle}' updated successfully",
            ];
        } catch (\Exception $e) {
            return [
                'error' => "Failed to update collection '{$handle}': " . $e->getMessage(),
                'handle' => $handle,
            ];
        }
    }
}
