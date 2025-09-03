<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Navigations;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Nav;

#[Title('Update Statamic Navigation')]
class UpdateNavigationTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.navigations.update';
    }

    protected function getToolDescription(): string
    {
        return 'Update an existing Statamic navigation configuration';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Navigation handle')
            ->required()
            ->string('title')
            ->description('Navigation title')
            ->optional()
            ->raw('sites', [
                'type' => 'array',
                'description' => 'Sites the navigation is available on',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->string('blueprint')
            ->description('Blueprint handle for navigation items')
            ->optional()
            ->raw('collections', [
                'type' => 'array',
                'description' => 'Collections to include in navigation',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->integer('max_depth')
            ->description('Maximum depth for navigation tree')
            ->optional()
            ->boolean('expects_root')
            ->description('Whether navigation expects a root page')
            ->optional()
            ->boolean('dry_run')
            ->description('Preview changes without updating')
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
        $newTitle = $arguments['title'] ?? null;
        $newSites = $arguments['sites'] ?? null;
        $newBlueprint = $arguments['blueprint'] ?? null;
        $newCollections = $arguments['collections'] ?? null;
        $newMaxDepth = $arguments['max_depth'] ?? null;
        $newExpectsRoot = $arguments['expects_root'] ?? null;
        $dryRun = $arguments['dry_run'] ?? false;

        $nav = Nav::find($handle);

        if (! $nav) {
            return $this->createErrorResponse("Navigation '{$handle}' not found")->toArray();
        }

        $changes = [];

        // Check for title change
        if ($newTitle !== null && $newTitle !== $nav->title()) {
            $changes['title'] = ['from' => $nav->title(), 'to' => $newTitle];
        }

        // Check for sites change
        if ($newSites !== null && $newSites !== $nav->sites()) {
            $changes['sites'] = ['from' => $nav->sites(), 'to' => $newSites];
        }

        // Check for blueprint change
        $currentBlueprint = $nav->blueprint()?->handle();
        if ($newBlueprint !== null && $newBlueprint !== $currentBlueprint) {
            $changes['blueprint'] = ['from' => $currentBlueprint, 'to' => $newBlueprint];
        }

        // Check for collections change
        $currentCollections = $nav->collections()?->map->handle()->all() ?? [];
        if ($newCollections !== null && $newCollections !== $currentCollections) {
            $changes['collections'] = ['from' => $currentCollections, 'to' => $newCollections];
        }

        // Check for max depth change
        if ($newMaxDepth !== null && $newMaxDepth !== $nav->maxDepth()) {
            $changes['max_depth'] = ['from' => $nav->maxDepth(), 'to' => $newMaxDepth];
        }

        // Check for expects root change
        if ($newExpectsRoot !== null && $newExpectsRoot !== $nav->expectsRoot()) {
            $changes['expects_root'] = ['from' => $nav->expectsRoot(), 'to' => $newExpectsRoot];
        }

        if (count($changes) === 0) {
            return [
                'handle' => $handle,
                'message' => 'No changes detected',
                'navigation' => [
                    'handle' => $nav->handle(),
                    'title' => $nav->title(),
                    'sites' => $nav->sites(),
                    'blueprint' => $nav->blueprint()?->handle(),
                    'collections' => $nav->collections()?->map->handle()->all() ?? [],
                    'max_depth' => $nav->maxDepth(),
                    'expects_root' => $nav->expectsRoot(),
                ],
            ];
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'handle' => $handle,
                'proposed_changes' => $changes,
                'has_changes' => true,
            ];
        }

        try {
            // Apply updates
            if (isset($changes['title'])) {
                $nav->title($newTitle);
            }

            if (isset($changes['sites'])) {
                $nav->sites($newSites);
            }

            if (isset($changes['blueprint'])) {
                $nav->blueprint($newBlueprint);
            }

            if (isset($changes['collections'])) {
                $nav->collections($newCollections);
            }

            if (isset($changes['max_depth'])) {
                $nav->maxDepth($newMaxDepth);
            }

            if (isset($changes['expects_root'])) {
                $nav->expectsRoot($newExpectsRoot);
            }

            $nav->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('navigation_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'navigation' => [
                    'handle' => $nav->handle(),
                    'title' => $nav->title(),
                    'sites' => $nav->sites(),
                    'blueprint' => $nav->blueprint()?->handle(),
                    'collections' => $nav->collections()?->map->handle()->all() ?? [],
                    'max_depth' => $nav->maxDepth(),
                    'expects_root' => $nav->expectsRoot(),
                ],
                'changes' => $changes,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not update navigation: ' . $e->getMessage())->toArray();
        }
    }
}
