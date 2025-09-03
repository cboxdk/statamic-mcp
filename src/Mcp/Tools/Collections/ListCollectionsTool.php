<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Collections;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Collection;

#[Title('List Statamic Collections')]
#[IsReadOnly]
class ListCollectionsTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.collections.list';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'List all Statamic collections with their configuration and entry counts';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_entry_counts')
            ->description('Include entry counts for each collection (may be slower)')
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
        $includeEntryCounts = $arguments['include_entry_counts'] ?? true;

        try {
            $collections = Collection::all()->map(function ($collection) use ($includeEntryCounts) {
                $data = [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    'route' => $collection->route(null),
                    'dated' => $collection->dated(),
                    'structured' => $collection->hasStructure(),
                    'sites' => $collection->sites()->all(),
                ];

                if ($includeEntryCounts) {
                    $data['entries_count'] = $collection->queryEntries()->count();
                }

                return $data;
            })->values()->toArray();

            return [
                'collections' => $collections,
                'count' => count($collections),
                'performance_note' => $includeEntryCounts ? 'Entry counts included' : 'Entry counts skipped for performance',
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to list collections: ' . $e->getMessage(),
            ];
        }
    }
}
