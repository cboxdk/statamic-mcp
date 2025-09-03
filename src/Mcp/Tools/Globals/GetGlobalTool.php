<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Globals;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\GlobalSet;

#[Title('Get Statamic Global Set')]
#[IsReadOnly]
class GetGlobalTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.globals.get';
    }

    protected function getToolDescription(): string
    {
        return 'Get a specific global set with its values and configuration';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Global set handle')
            ->required()
            ->string('site')
            ->description('Site to get values for (defaults to default site)')
            ->optional()
            ->boolean('include_blueprint')
            ->description('Include blueprint field definitions')
            ->optional()
            ->boolean('include_all_sites')
            ->description('Include values for all sites')
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
        $site = $arguments['site'] ?? null;
        $includeBlueprint = $arguments['include_blueprint'] ?? true;
        $includeAllSites = $arguments['include_all_sites'] ?? false;

        try {
            $globalSet = GlobalSet::find($handle);
            if (! $globalSet) {
                return $this->createErrorResponse("Global set '{$handle}' not found")->toArray();
            }

            $data = [
                'handle' => $globalSet->handle(),
                'title' => $globalSet->title(),
                'sites' => $globalSet->sites()->all(),
            ];

            // Add blueprint information
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
                                    'instructions' => $field->get('instructions'),
                                    'required' => $field->isRequired(),
                                    'config' => $field->config(),
                                ];
                            })->values()->toArray(),
                        ];
                    })->toArray(),
                ];
            }

            // Add values
            if ($includeAllSites) {
                $data['values'] = [];
                foreach ($globalSet->sites()->all() as $siteHandle) {
                    try {
                        $variables = $globalSet->in($siteHandle);
                        $data['values'][$siteHandle] = $variables ? $variables->data()->toArray() : [];
                    } catch (\Exception $e) {
                        $data['values'][$siteHandle] = [];
                    }
                }
            } else {
                // Get values for specific site or default site
                $targetSite = $site ?? $globalSet->sites()->first();
                try {
                    $variables = $globalSet->in($targetSite);
                    $data['values'] = $variables ? $variables->data()->toArray() : [];
                    $data['current_site'] = $targetSite;
                } catch (\Exception $e) {
                    $data['values'] = [];
                    $data['current_site'] = $targetSite;
                    $data['warning'] = "Could not load values for site '{$targetSite}'";
                }
            }

            return [
                'global' => $data,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not get global set: ' . $e->getMessage())->toArray();
        }
    }
}
