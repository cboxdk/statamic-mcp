<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Collections;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Collection;

#[Title('Get Statamic Collection')]
#[IsReadOnly]
class GetCollectionTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.collections.get';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Get detailed information about a specific Statamic collection';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Collection handle/identifier')
            ->required();
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

            $blueprints = $collection->entryBlueprints()->map(function ($blueprint) {
                return [
                    'handle' => $blueprint->handle(),
                    'title' => $blueprint->title(),
                ];
            })->values()->toArray();

            return [
                'collection' => [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    'route' => $collection->route(null),
                    'layout' => $collection->layout(),
                    'template' => $collection->template(),
                    'dated' => $collection->dated(),
                    'structured' => $collection->hasStructure(),
                    'max_depth' => $collection->hasStructure() ? $collection->structure()->maxDepth() : null,
                    'sites' => $collection->sites()->all(),
                    'taxonomies' => $collection->taxonomies()->all(),
                    'blueprints' => $blueprints,
                    'entries_count' => $collection->queryEntries()->count(),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'error' => "Failed to get collection '{$handle}': " . $e->getMessage(),
                'handle' => $handle,
            ];
        }
    }
}
