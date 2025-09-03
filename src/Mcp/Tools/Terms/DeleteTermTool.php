<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Terms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Term;

#[Title('Delete Statamic Term')]
class DeleteTermTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.terms.delete';
    }

    protected function getToolDescription(): string
    {
        return 'Delete a taxonomy term with safety checks';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('id')
            ->description('Term ID or taxonomy::slug format')
            ->required()
            ->boolean('force')
            ->description('Force delete even if term is referenced by entries')
            ->optional()
            ->boolean('dry_run')
            ->description('Check what would be deleted without actually deleting')
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
        $id = $arguments['id'];
        $force = $arguments['force'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            $term = Term::find($id);
            if (! $term) {
                return $this->createErrorResponse("Term '{$id}' not found")->toArray();
            }

            // Check for related entries
            $relatedEntries = $term->queryEntries()->get();
            $hasRelatedEntries = $relatedEntries->isNotEmpty();

            if ($hasRelatedEntries && ! $force) {
                return $this->createErrorResponse(
                    "Term is referenced by {$relatedEntries->count()} entries. Use 'force' parameter to delete anyway."
                )->toArray();
            }

            $termData = [
                'id' => $term->id(),
                'title' => $term->get('title'),
                'slug' => $term->slug(),
                'taxonomy' => $term->taxonomy()->handle(),
                'published' => $term->published(),
            ];

            if ($dryRun) {
                $relatedData = $relatedEntries->map(function ($entry) {
                    return [
                        'id' => $entry->id(),
                        'title' => $entry->get('title'),
                        'collection' => $entry->collection()->handle(),
                    ];
                });

                return [
                    'dry_run' => true,
                    'would_delete' => $termData,
                    'related_entries' => $relatedData->toArray(),
                    'related_count' => $relatedEntries->count(),
                    'warnings' => $hasRelatedEntries ? ['Term is referenced by entries and will be removed from them'] : [],
                ];
            }

            // Delete the term
            $term->delete();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('content_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'deleted' => $termData,
                'related_entries_affected' => $relatedEntries->count(),
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not delete term: ' . $e->getMessage())->toArray();
        }
    }
}
