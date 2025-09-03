<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Globals;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\GlobalSet;

#[Title('List Statamic Global Sets')]
#[IsReadOnly]
class ListGlobalsTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.globals.list';
    }

    protected function getToolDescription(): string
    {
        return 'List global sets with their configuration and current values';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_values')
            ->description('Include current global values for each set')
            ->optional()
            ->boolean('include_blueprint')
            ->description('Include blueprint details for each global set')
            ->optional()
            ->string('site')
            ->description('Get values for specific site')
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
        $includeValues = $arguments['include_values'] ?? false;
        $includeBlueprint = $arguments['include_blueprint'] ?? false;
        $site = $arguments['site'] ?? null;

        try {
            $globalSets = GlobalSet::all()->map(function ($globalSet) use ($includeValues, $includeBlueprint, $site) {
                $data = [
                    'handle' => $globalSet->handle(),
                    'title' => $globalSet->title(),
                    'sites' => $globalSet->sites()->all(),
                ];

                if ($includeBlueprint && $globalSet->blueprint()) {
                    $blueprint = $globalSet->blueprint();
                    $data['blueprint'] = [
                        'handle' => $blueprint->handle(),
                        'title' => $blueprint->title(),
                        'sections' => $blueprint->sections()->map(function ($section) {
                            return [
                                'handle' => $section->handle(),
                                'display' => $section->display(),
                                'fields' => $section->fields()->map(function ($field) {
                                    return [
                                        'handle' => $field->handle(),
                                        'type' => $field->type(),
                                        'display' => $field->display(),
                                        'required' => $field->isRequired(),
                                    ];
                                })->values()->toArray(),
                            ];
                        })->toArray(),
                    ];
                }

                if ($includeValues) {
                    $data['values'] = [];
                    $sitesToCheck = $site ? [$site] : $globalSet->sites()->all();

                    foreach ($sitesToCheck as $siteHandle) {
                        try {
                            $variables = $globalSet->in($siteHandle);
                            $data['values'][$siteHandle] = $variables ? $variables->data()->toArray() : [];
                        } catch (\Exception $e) {
                            $data['values'][$siteHandle] = [];
                        }
                    }

                    // If single site requested, flatten the structure
                    if ($site && count($data['values']) === 1) {
                        $data['values'] = $data['values'][$site] ?? [];
                    }
                }

                return $data;
            })->values()->toArray();

            return [
                'globals' => $globalSets,
                'count' => count($globalSets),
                'filters' => [
                    'site' => $site,
                    'include_values' => $includeValues,
                    'include_blueprint' => $includeBlueprint,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not list global sets: ' . $e->getMessage())->toArray();
        }
    }
}
