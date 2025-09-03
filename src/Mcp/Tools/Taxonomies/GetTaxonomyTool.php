<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Taxonomy;

#[Title('Get Statamic Taxonomy')]
#[IsReadOnly]
class GetTaxonomyTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.taxonomies.get';
    }

    protected function getToolDescription(): string
    {
        return 'Get detailed information about a specific Statamic taxonomy';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Taxonomy handle')
            ->required()
            ->boolean('include_blueprint')
            ->description('Include blueprint structure')
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
        $handle = $arguments['handle'];
        $includeBlueprint = $arguments['include_blueprint'] ?? true;

        try {
            $taxonomy = Taxonomy::findByHandle($handle);

            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy '{$handle}' not found")->toArray();
            }

            $taxonomyData = [
                'handle' => $taxonomy->handle(),
                'title' => $taxonomy->title(),
                'sites' => $taxonomy->sites(),
                'blueprint' => $taxonomy->blueprint()?->handle(),
                'path' => $taxonomy->path(),
                'collections' => $taxonomy->collections()?->map(fn ($item) => $item->handle())->all() ?? [],
                'preview_targets' => $taxonomy->previewTargets(),
            ];

            if ($includeBlueprint && $taxonomy->blueprint()) {
                $taxonomyData['blueprint_structure'] = [
                    'handle' => $taxonomy->blueprint()->handle(),
                    'title' => $taxonomy->blueprint()->title(),
                    'fields' => $taxonomy->blueprint()->fields()->all()->map(function ($field) {
                        return [
                            'handle' => $field->handle(),
                            'type' => $field->type(),
                            'display' => $field->display(),
                            'config' => $field->config(),
                        ];
                    })->toArray(),
                ];
            }

            return [
                'taxonomy' => $taxonomyData,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not get taxonomy: ' . $e->getMessage())->toArray();
        }
    }
}
