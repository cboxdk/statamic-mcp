<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

#[Title('Delete Statamic Taxonomy')]
class DeleteTaxonomyTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.taxonomies.delete';
    }

    protected function getToolDescription(): string
    {
        return 'Delete a Statamic taxonomy with safety checks';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Taxonomy handle')
            ->required()
            ->boolean('force')
            ->description('Force deletion even if taxonomy has terms or is used by collections')
            ->optional()
            ->boolean('dry_run')
            ->description('Preview what would be deleted without actually deleting')
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
        $force = $arguments['force'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            $taxonomy = Taxonomy::findByHandle($handle);

            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy '{$handle}' not found")->toArray();
            }

            // Safety checks
            $warnings = [];
            $termCount = Term::whereTaxonomy($handle)->count();
            $usedByCollections = $taxonomy->collections()?->map->handle()->all() ?? [];

            if ($termCount > 0) {
                $warnings[] = "Taxonomy has {$termCount} terms";
            }

            if (! empty($usedByCollections)) {
                $warnings[] = 'Taxonomy is used by collections: ' . implode(', ', $usedByCollections);
            }

            if (! empty($warnings) && ! $force && ! $dryRun) {
                return $this->createErrorResponse(
                    'Cannot delete taxonomy. ' . implode('. ', $warnings) . '. Use force=true to override.'
                )->toArray();
            }

            if ($dryRun) {
                return [
                    'dry_run' => true,
                    'would_delete' => [
                        'handle' => $handle,
                        'title' => $taxonomy->title(),
                        'path' => $taxonomy->path(),
                    ],
                    'warnings' => $warnings,
                    'term_count' => $termCount,
                    'used_by_collections' => $usedByCollections,
                ];
            }

            $taxonomyData = [
                'handle' => $taxonomy->handle(),
                'title' => $taxonomy->title(),
                'path' => $taxonomy->path(),
            ];

            // Delete all terms if forcing
            if ($force && $termCount > 0) {
                $terms = Term::whereTaxonomy($handle)->all();
                foreach ($terms as $term) {
                    $term->delete();
                }
            }

            // Delete taxonomy
            $taxonomy->delete();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('taxonomy_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'deleted' => $taxonomyData,
                'terms_deleted' => $force ? $termCount : 0,
                'warnings' => $warnings,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not delete taxonomy: ' . $e->getMessage())->toArray();
        }
    }
}
