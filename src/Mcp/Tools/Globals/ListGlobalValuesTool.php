<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Globals;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;

#[Title('List Global Values')]
#[IsReadOnly]
class ListGlobalValuesTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.globals.values.list';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'List all global values (content) across all global sets and sites';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('site')
            ->description('Site handle to get values for (defaults to default site)')
            ->optional()
            ->string('global_set')
            ->description('Specific global set handle to filter by')
            ->optional()
            ->boolean('include_empty_values')
            ->description('Include fields with empty/null values')
            ->optional()
            ->integer('limit')
            ->description('Limit number of global sets to process')
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
        $siteHandle = $arguments['site'] ?? Site::default()->handle();
        $globalSetHandle = $arguments['global_set'] ?? null;
        $includeEmptyValues = $arguments['include_empty_values'] ?? false;
        $limit = $arguments['limit'] ?? null;

        try {
            // Validate site
            if (! Site::all()->map(fn ($item) => $item->handle())->contains($siteHandle)) {
                return $this->createErrorResponse("Site '{$siteHandle}' not found", [
                    'available_sites' => Site::all()->map(fn ($item) => $item->handle())->all(),
                ])->toArray();
            }

            $globalSets = GlobalSet::all();

            // Filter by specific global set if provided
            if ($globalSetHandle) {
                $globalSets = $globalSets->filter(fn ($set) => $set->handle() === $globalSetHandle);

                if ($globalSets->isEmpty()) {
                    return $this->createErrorResponse("Global set '{$globalSetHandle}' not found", [
                        'available_sets' => GlobalSet::all()->map(fn ($item) => $item->handle())->all(),
                    ])->toArray();
                }
            }

            // Apply limit if specified
            if ($limit) {
                $globalSets = $globalSets->take($limit);
            }

            $allGlobalValues = [];

            foreach ($globalSets as $globalSet) {
                $localizedSet = $globalSet->in($siteHandle);

                if (! $localizedSet) {
                    continue;
                }

                $values = $localizedSet->data()->all();

                if (! $includeEmptyValues) {
                    $values = array_filter($values, function ($value) {
                        return $value !== null && $value !== '' && $value !== [];
                    });
                }

                if (! empty($values) || $includeEmptyValues) {
                    $allGlobalValues[$globalSet->handle()] = [
                        'title' => $globalSet->title(),
                        'handle' => $globalSet->handle(),
                        'site' => $siteHandle,
                        'values' => $values,
                        'value_count' => count($values),
                        'last_modified' => $localizedSet->fileLastModified()?->toISOString(),
                    ];
                }
            }

            return [
                'global_values' => $allGlobalValues,
                'site' => $siteHandle,
                'total_sets_with_values' => count($allGlobalValues),
                'meta' => [
                    'filtered_by_set' => $globalSetHandle,
                    'empty_values_included' => $includeEmptyValues,
                    'limit_applied' => $limit,
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to list global values: ' . $e->getMessage())->toArray();
        }
    }
}
