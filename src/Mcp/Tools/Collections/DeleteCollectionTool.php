<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Collections;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Collection;

#[Title('Delete Statamic Collection')]
class DeleteCollectionTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.collections.delete';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Delete a Statamic collection with safety checks and optional force deletion';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Collection handle/identifier')
            ->required()
            ->boolean('force')
            ->description('Force deletion even if collection has entries')
            ->optional()
            ->boolean('dry_run')
            ->description('Show what would be deleted without actually deleting')
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
        $force = $arguments['force'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            $collection = Collection::find($handle);

            if (! $collection) {
                return [
                    'error' => "Collection '{$handle}' not found",
                    'handle' => $handle,
                ];
            }

            $entriesCount = $collection->queryEntries()->count();
            $hasEntries = $entriesCount > 0;

            if ($dryRun) {
                return [
                    'dry_run' => true,
                    'handle' => $handle,
                    'title' => $collection->title(),
                    'entries_count' => $entriesCount,
                    'has_entries' => $hasEntries,
                    'can_delete_safely' => ! $hasEntries,
                    'would_force_delete' => $force && $hasEntries,
                    'warnings' => $hasEntries ? ["Collection has {$entriesCount} entries"] : [],
                ];
            }

            if ($hasEntries && ! $force) {
                return [
                    'error' => "Cannot delete collection '{$handle}' - it contains {$entriesCount} entries",
                    'handle' => $handle,
                    'entries_count' => $entriesCount,
                    'suggestion' => 'Use force=true to delete anyway, or dry_run=true to see what would be affected',
                ];
            }

            $collectionTitle = $collection->title();
            $collection->delete();
            $cacheResult = $this->clearStatamicCaches($this->getRecommendedCacheTypes('collection_change'));

            return [
                'handle' => $handle,
                'title' => $collectionTitle,
                'deleted' => true,
                'forced' => $force,
                'entries_at_deletion' => $entriesCount,
                'cache_cleared' => $cacheResult['cache_cleared'] ?? false,
                'message' => "Collection '{$handle}' deleted successfully",
                'warnings' => $force && $hasEntries ?
                    ["Forced deletion: {$entriesCount} entries were also deleted"] : [],
            ];
        } catch (\Exception $e) {
            return [
                'error' => "Failed to delete collection '{$handle}': " . $e->getMessage(),
                'handle' => $handle,
            ];
        }
    }
}
