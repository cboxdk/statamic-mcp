<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Taxonomy;

#[Title('Update Statamic Taxonomy')]
class UpdateTaxonomyTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.taxonomies.update';
    }

    protected function getToolDescription(): string
    {
        return 'Update an existing Statamic taxonomy configuration';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Taxonomy handle')
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
            ->description('Preview changes without updating')
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
        $title = $arguments['title'] ?? null;
        $sites = $arguments['sites'] ?? null;
        $collections = $arguments['collections'] ?? null;
        $blueprint = $arguments['blueprint'] ?? null;
        $previewTargets = $arguments['preview_targets'] ?? null;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            $taxonomy = Taxonomy::findByHandle($handle);

            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy '{$handle}' not found")->toArray();
            }

            $changes = [];
            $originalData = [
                'title' => $taxonomy->title(),
                'sites' => $taxonomy->sites(),
                'collections' => $taxonomy->collections()?->map->handle()->all() ?? [],
                'blueprint' => $taxonomy->blueprint()?->handle(),
                'preview_targets' => $taxonomy->previewTargets(),
            ];

            if ($dryRun) {
                $proposedChanges = [];

                if ($title !== null && $title !== $taxonomy->title()) {
                    $proposedChanges['title'] = ['from' => $taxonomy->title(), 'to' => $title];
                }

                if ($sites !== null && $sites !== $taxonomy->sites()) {
                    $proposedChanges['sites'] = ['from' => $taxonomy->sites(), 'to' => $sites];
                }

                if ($collections !== null && $collections !== ($taxonomy->collections()?->map->handle()->all() ?? [])) {
                    $proposedChanges['collections'] = ['from' => $taxonomy->collections()?->map->handle()->all() ?? [], 'to' => $collections];
                }

                if ($blueprint !== null && $blueprint !== $taxonomy->blueprint()?->handle()) {
                    $proposedChanges['blueprint'] = ['from' => $taxonomy->blueprint()?->handle(), 'to' => $blueprint];
                }

                if ($previewTargets !== null && $previewTargets !== $taxonomy->previewTargets()) {
                    $proposedChanges['preview_targets'] = ['from' => $taxonomy->previewTargets(), 'to' => $previewTargets];
                }

                return [
                    'dry_run' => true,
                    'handle' => $handle,
                    'proposed_changes' => $proposedChanges,
                    'has_changes' => ! empty($proposedChanges),
                ];
            }

            // Apply updates
            if ($title !== null && $title !== $taxonomy->title()) {
                $taxonomy->title($title);
                $changes['title'] = ['from' => $originalData['title'], 'to' => $title];
            }

            if ($sites !== null && $sites !== $taxonomy->sites()) {
                $taxonomy->sites($sites);
                $changes['sites'] = ['from' => $originalData['sites'], 'to' => $sites];
            }

            if ($collections !== null && $collections !== ($taxonomy->collections()?->map->handle()->all() ?? [])) {
                $taxonomy->collections($collections);
                $changes['collections'] = ['from' => $originalData['collections'], 'to' => $collections];
            }

            if ($blueprint !== null && $blueprint !== $taxonomy->blueprint()?->handle()) {
                $taxonomy->blueprint($blueprint);
                $changes['blueprint'] = ['from' => $originalData['blueprint'], 'to' => $blueprint];
            }

            if ($previewTargets !== null && $previewTargets !== $taxonomy->previewTargets()) {
                $taxonomy->previewTargets($previewTargets);
                $changes['preview_targets'] = ['from' => $originalData['preview_targets'], 'to' => $previewTargets];
            }

            if (empty($changes)) {
                return [
                    'handle' => $handle,
                    'message' => 'No changes detected',
                    'taxonomy' => $originalData,
                ];
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
                'changes' => $changes,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not update taxonomy: ' . $e->getMessage())->toArray();
        }
    }
}
