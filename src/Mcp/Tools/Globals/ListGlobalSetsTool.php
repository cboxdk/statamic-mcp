<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Globals;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\GlobalSet;

#[Title('List Global Sets')]
#[IsReadOnly]
class ListGlobalSetsTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.globals.sets.list';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'List all Statamic global sets (structures) with their configuration';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_blueprint_info')
            ->description('Include blueprint information for each global set')
            ->optional()
            ->boolean('include_localizations')
            ->description('Include localization information')
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
        $includeBlueprintInfo = $arguments['include_blueprint_info'] ?? true;
        $includeLocalizations = $arguments['include_localizations'] ?? false;

        try {
            $globalSets = GlobalSet::all()->map(function ($globalSet) use ($includeBlueprintInfo, $includeLocalizations) {
                $data = [
                    'handle' => $globalSet->handle(),
                    'title' => $globalSet->title(),
                    'sites' => $globalSet->sites()->all(),
                ];

                if ($includeBlueprintInfo && $globalSet->blueprint()) {
                    $blueprint = $globalSet->blueprint();
                    $data['blueprint'] = [
                        'handle' => $blueprint->handle(),
                        'title' => $blueprint->title(),
                        'field_count' => count($blueprint->fields()->all()),
                        'has_fields' => ! $blueprint->fields()->all()->isEmpty(),
                    ];
                }

                if ($includeLocalizations) {
                    $localizations = [];
                    foreach ($globalSet->sites() as $siteHandle) {
                        $localizedSet = $globalSet->in($siteHandle);
                        $localizations[$siteHandle] = [
                            'exists' => $localizedSet !== null,
                            'has_data' => $localizedSet ? ! empty($localizedSet->data()->all()) : false,
                        ];
                    }
                    $data['localizations'] = $localizations;
                }

                return $data;
            })->values()->all();

            return [
                'global_sets' => $globalSets,
                'count' => count($globalSets),
                'meta' => [
                    'blueprint_info_included' => $includeBlueprintInfo,
                    'localizations_included' => $includeLocalizations,
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to list global sets: ' . $e->getMessage())->toArray();
        }
    }
}
