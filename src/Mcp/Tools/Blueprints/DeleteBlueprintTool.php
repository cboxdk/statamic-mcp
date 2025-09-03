<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Blueprints;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;

#[Title('Delete Statamic Blueprint')]
class DeleteBlueprintTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.blueprints.delete';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Delete a blueprint with safety checks for existing content usage';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Blueprint handle/identifier to delete')
            ->required()
            ->string('namespace')
            ->description('Blueprint namespace (collections, forms, taxonomies, globals, assets, users, navs)')
            ->optional()
            ->boolean('force')
            ->description('Force deletion even if content exists that uses this blueprint')
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
        $handle = $arguments['handle'];
        $namespace = $arguments['namespace'] ?? 'collections';
        $force = $arguments['force'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            // Find the blueprint
            $blueprint = Blueprint::find("{$namespace}.{$handle}");
            if (! $blueprint) {
                return [
                    'error' => "Blueprint '{$handle}' not found in namespace '{$namespace}'",
                    'handle' => $handle,
                    'namespace' => $namespace,
                ];
            }

            // Check for content using this blueprint
            $usageInfo = $this->checkBlueprintUsage($handle, $namespace);

            if ($dryRun) {
                return [
                    'dry_run' => true,
                    'handle' => $handle,
                    'namespace' => $namespace,
                    'title' => $blueprint->title(),
                    'usage' => $usageInfo,
                    'can_delete_safely' => $usageInfo['total_usage'] === 0,
                    'would_force_delete' => $force,
                    'warnings' => $this->generateDeletionWarnings($usageInfo, $force),
                ];
            }

            // Safety check: prevent deletion if content exists and force is not set
            if ($usageInfo['total_usage'] > 0 && ! $force) {
                return [
                    'error' => "Cannot delete blueprint '{$handle}' - it is used by {$usageInfo['total_usage']} content items",
                    'handle' => $handle,
                    'namespace' => $namespace,
                    'usage' => $usageInfo,
                    'suggestion' => 'Use force=true to delete anyway, or dry_run=true to see what would be affected',
                ];
            }

            // Store info for response before deletion
            $blueprintTitle = $blueprint->title();

            // Perform the deletion
            $blueprint->delete();

            // Clear relevant caches
            $cacheResult = $this->clearStatamicCaches($this->getRecommendedCacheTypes('blueprint_change'));

            return [
                'success' => true,
                'handle' => $handle,
                'namespace' => $namespace,
                'title' => $blueprintTitle,
                'deleted' => true,
                'forced' => $force,
                'usage_at_deletion' => $usageInfo,
                'cache_cleared' => $cacheResult['cache_cleared'] ?? false,
                'message' => "Blueprint '{$handle}' deleted successfully from namespace '{$namespace}'",
                'warnings' => $force && $usageInfo['total_usage'] > 0 ?
                    ["Forced deletion: {$usageInfo['total_usage']} content items may now have missing blueprint references"] : [],
            ];
        } catch (\Exception $e) {
            return [
                'error' => "Failed to delete blueprint '{$handle}' from namespace '{$namespace}': " . $e->getMessage(),
                'handle' => $handle,
                'namespace' => $namespace,
            ];
        }
    }

    /**
     * Check how the blueprint is being used.
     *
     * @return array<string, mixed>
     */
    private function checkBlueprintUsage(string $handle, string $namespace): array
    {
        $usage = [
            'entries' => 0,
            'collections_using' => [],
            'total_usage' => 0,
        ];

        try {
            if ($namespace === 'collections') {
                // Check if any entries use this blueprint
                $entries = Entry::query()
                    ->where('blueprint', $handle)
                    ->get();

                $usage['entries'] = $entries->count();

                // Group by collection
                $usage['collections_using'] = $entries->groupBy('collection')
                    ->map(fn ($entries): int => $entries->count())
                    ->toArray();
            }

            // Add other namespace checks as needed (forms, taxonomies, etc.)

            $usage['total_usage'] = $usage['entries'];

        } catch (\Exception $e) {
            // If we can't check usage, be conservative
            $usage['check_error'] = $e->getMessage();
            $usage['total_usage'] = -1; // Unknown usage
        }

        return $usage;
    }

    /**
     * Generate deletion warnings based on usage.
     *
     * @param  array<string, mixed>  $usage
     *
     * @return array<string>
     */
    private function generateDeletionWarnings(array $usage, bool $force): array
    {
        $warnings = [];

        if ($usage['total_usage'] > 0) {
            if ($force) {
                $warnings[] = "Blueprint is used by {$usage['total_usage']} content items - forced deletion may cause issues";

                if (! empty($usage['collections_using'])) {
                    foreach ($usage['collections_using'] as $collection => $count) {
                        $warnings[] = "Collection '{$collection}': {$count} entries affected";
                    }
                }
            } else {
                $warnings[] = 'Blueprint cannot be safely deleted - use force=true to override';
            }
        }

        return $warnings;
    }
}
