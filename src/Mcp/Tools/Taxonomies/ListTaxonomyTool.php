<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Taxonomy;

#[Title('List Statamic Taxonomies')]
#[IsReadOnly]
class ListTaxonomyTool extends BaseStatamicTool
{
    use \Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.taxonomies.list';
    }

    protected function getToolDescription(): string
    {
        return 'List all Statamic taxonomies with optional filtering and metadata';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema = $this->addLimitSchema($schema);

        return $schema->boolean('include_meta')
            ->description('Include metadata and configuration')
            ->optional()
            ->string('filter')
            ->description('Filter results by name/handle')
            ->optional()
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
        $includeMeta = $arguments['include_meta'] ?? true;
        $includeBlueprint = $arguments['include_blueprint'] ?? false;
        $filter = $arguments['filter'] ?? null;
        $limit = $arguments['limit'] ?? null;

        $taxonomies = [];

        try {
            $allTaxonomies = Taxonomy::all();

            foreach ($allTaxonomies as $taxonomy) {
                if ($filter && ! str_contains($taxonomy->handle(), $filter) && ! str_contains($taxonomy->title(), $filter)) {
                    continue;
                }

                $taxonomyData = [
                    'handle' => $taxonomy->handle(),
                    'title' => $taxonomy->title(),
                ];

                if ($includeMeta) {
                    $taxonomyData['sites'] = $taxonomy->sites();
                    $taxonomyData['blueprint'] = $taxonomy->blueprint()?->handle();
                    $taxonomyData['path'] = $taxonomy->path();
                    $taxonomyData['collections'] = $taxonomy->collections()?->map->handle()->all() ?? [];
                    $taxonomyData['preview_targets'] = $taxonomy->previewTargets();
                }

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

                $taxonomies[] = $taxonomyData;
            }
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not list taxonomies: ' . $e->getMessage())->toArray();
        }

        if ($limit) {
            $taxonomies = array_slice($taxonomies, 0, $limit);
        }

        return [
            'taxonomies' => $taxonomies,
            'count' => count($taxonomies),
            'total_available' => Taxonomy::all()->count(),
        ];
    }
}
