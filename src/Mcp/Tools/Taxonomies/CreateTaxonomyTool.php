<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Taxonomy;

#[Title('Create Statamic Taxonomy')]
class CreateTaxonomyTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.taxonomies.create';
    }

    protected function getToolDescription(): string
    {
        return 'Create a new Statamic taxonomy with configuration';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Taxonomy handle (unique identifier)')
            ->required()
            ->string('title')
            ->description('Taxonomy title')
            ->optional()
            ->raw('sites', [
                'type' => 'array',
                'description' => 'Sites where this taxonomy is available',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->raw('collections', [
                'type' => 'array',
                'description' => 'Collections that use this taxonomy',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->string('blueprint')
            ->description('Blueprint handle for terms')
            ->optional()
            ->raw('preview_targets', [
                'type' => 'array',
                'description' => 'Preview target configurations',
                'items' => ['type' => 'object'],
            ])
            ->optional()
            ->boolean('dry_run')
            ->description('Preview changes without creating')
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
        $title = $arguments['title'] ?? ucfirst($handle);
        $sites = $arguments['sites'] ?? null;
        $collections = $arguments['collections'] ?? [];
        $blueprint = $arguments['blueprint'] ?? null;
        $previewTargets = $arguments['preview_targets'] ?? [];
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            // Check if taxonomy already exists
            if (Taxonomy::findByHandle($handle)) {
                return $this->createErrorResponse("Taxonomy '{$handle}' already exists")->toArray();
            }

            $taxonomyData = [
                'handle' => $handle,
                'title' => $title,
            ];

            if ($sites) {
                $taxonomyData['sites'] = $sites;
            }

            if (! empty($collections)) {
                $taxonomyData['collections'] = $collections;
            }

            if ($blueprint) {
                $taxonomyData['blueprints'] = [$blueprint];
            }

            if (! empty($previewTargets)) {
                $taxonomyData['preview_targets'] = $previewTargets;
            }

            if ($dryRun) {
                return [
                    'dry_run' => true,
                    'would_create' => $taxonomyData,
                    'file_path' => resource_path("taxonomies/{$handle}.yaml"),
                ];
            }

            // Create taxonomy
            $taxonomy = Taxonomy::make($handle)
                ->title($title);

            if ($sites) {
                $taxonomy->sites($sites);
            }

            if (! empty($collections)) {
                $taxonomy->collections($collections);
            }

            if ($blueprint) {
                $taxonomy->blueprint($blueprint);
            }

            if (! empty($previewTargets)) {
                $taxonomy->previewTargets($previewTargets);
            }

            $taxonomy->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('taxonomy_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'taxonomy' => [
                    'handle' => $taxonomy->handle(),
                    'title' => $taxonomy->title(),
                    'sites' => $taxonomy->sites(),
                    'collections' => $taxonomy->collections()?->map->handle()->all() ?? [],
                    'blueprint' => $taxonomy->blueprint()?->handle(),
                    'preview_targets' => $taxonomy->previewTargets(),
                    'path' => $taxonomy->path(),
                ],
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not create taxonomy: ' . $e->getMessage())->toArray();
        }
    }
}
