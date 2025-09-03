<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Globals;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;

#[Title('Get Global Values')]
#[IsReadOnly]
class GetGlobalValuesTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.globals.values.get';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Get specific global values (content) from a global set';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('global_set')
            ->description('Global set handle to get values from')
            ->required()
            ->string('site')
            ->description('Site handle to get values for (defaults to default site)')
            ->optional()
            ->raw('fields', [
                'type' => 'array',
                'description' => 'Specific field handles to retrieve (returns all if not specified)',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->boolean('include_metadata')
            ->description('Include metadata about the global set and values')
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
        $globalSetHandle = $arguments['global_set'];
        $siteHandle = $arguments['site'] ?? Site::default()->handle();
        $specificFields = $arguments['fields'] ?? null;
        $includeMetadata = $arguments['include_metadata'] ?? true;

        try {
            // Validate site
            if (! Site::all()->map(fn ($item) => $item->handle())->contains($siteHandle)) {
                return $this->createErrorResponse("Site '{$siteHandle}' not found", [
                    'available_sites' => Site::all()->map(fn ($item) => $item->handle())->all(),
                ])->toArray();
            }

            $globalSet = GlobalSet::findByHandle($globalSetHandle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set '{$globalSetHandle}' not found", [
                    'available_sets' => GlobalSet::all()->map(fn ($item) => $item->handle())->all(),
                ])->toArray();
            }

            $localizedSet = $globalSet->in($siteHandle);

            if (! $localizedSet) {
                return $this->createErrorResponse("Global set '{$globalSetHandle}' does not exist for site '{$siteHandle}'", [
                    'available_sites_for_set' => $globalSet->sites()->all(),
                ])->toArray();
            }

            $allValues = $localizedSet->data()->all();

            // Filter to specific fields if requested
            if ($specificFields && is_array($specificFields)) {
                $values = [];
                foreach ($specificFields as $field) {
                    if (array_key_exists($field, $allValues)) {
                        $values[$field] = $allValues[$field];
                    }
                }
            } else {
                $values = $allValues;
            }

            $result = [
                'global_set_handle' => $globalSetHandle,
                'site' => $siteHandle,
                'values' => $values,
            ];

            if ($includeMetadata) {
                $result['metadata'] = [
                    'global_set_title' => $globalSet->title(),
                    'total_available_fields' => count($allValues),
                    'returned_fields' => count($values),
                    'requested_specific_fields' => $specificFields !== null,
                    'last_modified' => $localizedSet->fileLastModified()?->toISOString(),
                    'file_path' => $localizedSet->path(),
                ];

                // Include blueprint information if available
                if ($blueprint = $globalSet->blueprint()) {
                    $result['metadata']['blueprint'] = [
                        'handle' => $blueprint->handle(),
                        'title' => $blueprint->title(),
                        'field_definitions' => array_keys($blueprint->fields()->all()->toArray()),
                    ];
                }

                // Include information about missing requested fields
                if ($specificFields) {
                    $missingFields = array_diff($specificFields, array_keys($allValues));
                    if (! empty($missingFields)) {
                        $result['metadata']['missing_requested_fields'] = $missingFields;
                        $result['metadata']['available_fields'] = array_keys($allValues);
                    }
                }
            }

            return $result;

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to get global values: ' . $e->getMessage())->toArray();
        }
    }
}
