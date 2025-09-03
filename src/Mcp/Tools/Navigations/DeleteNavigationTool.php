<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Navigations;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Nav;

#[Title('Delete Statamic Navigation')]
class DeleteNavigationTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.navigations.delete';
    }

    protected function getToolDescription(): string
    {
        return 'Delete a Statamic navigation with safety checks';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Navigation handle')
            ->required()
            ->boolean('force')
            ->description('Force deletion without safety checks')
            ->optional()
            ->boolean('dry_run')
            ->description('Preview what would be deleted without actually deleting')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $handle = $arguments['handle'];
        $force = $arguments['force'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        $nav = Nav::find($handle);

        if (! $nav) {
            return $this->createErrorResponse("Navigation '{$handle}' not found")->toArray();
        }

        // Safety checks - check if navigation has content
        $warnings = [];
        $usage = $this->checkNavigationUsage($nav);

        if (! empty($usage['trees_with_content'])) {
            $warnings[] = 'Navigation has content in ' . count($usage['trees_with_content']) . ' site(s): ' . implode(', ', array_keys($usage['trees_with_content']));
        }

        if (! empty($warnings) && ! $force && ! $dryRun) {
            return $this->createErrorResponse(
                'Cannot delete navigation. ' . implode('. ', $warnings) . '. Use force=true to override.'
            )->toArray();
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_delete' => [
                    'handle' => $handle,
                    'title' => $nav->title(),
                    'sites' => $nav->sites(),
                    'content_items' => $usage['total_content_items'],
                ],
                'warnings' => $warnings,
                'usage' => $usage,
            ];
        }

        try {
            $navData = [
                'handle' => $nav->handle(),
                'title' => $nav->title(),
                'sites' => $nav->sites(),
                'blueprint' => $nav->blueprint()?->handle(),
                'collections' => $nav->collections()?->map->handle()->all() ?? [],
                'max_depth' => $nav->maxDepth(),
                'expects_root' => $nav->expectsRoot(),
            ];

            // Delete navigation
            $nav->delete();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('navigation_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'deleted' => $navData,
                'warnings' => $warnings,
                'usage_at_deletion' => $usage,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not delete navigation: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Check where the navigation is being used.
     *
     * @param  mixed  $nav
     *
     * @return array<string, mixed>
     */
    private function checkNavigationUsage($nav): array
    {
        $usage = [
            'trees_with_content' => [],
            'total_content_items' => 0,
        ];

        try {
            foreach ($nav->sites() as $site) {
                $tree = $nav->in($site)?->tree();
                if ($tree && $tree->count() > 0) {
                    $usage['trees_with_content'][$site] = $tree->count();
                    $usage['total_content_items'] += $tree->count();
                }
            }
        } catch (\Exception) {
            // Silently ignore errors in usage checking
        }

        return $usage;
    }
}
