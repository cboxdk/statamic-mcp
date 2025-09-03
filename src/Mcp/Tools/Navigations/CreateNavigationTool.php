<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Navigations;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Nav;

#[Title('Create Statamic Navigation')]
class CreateNavigationTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.navigations.create';
    }

    protected function getToolDescription(): string
    {
        return 'Create a new Statamic navigation with configuration';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Navigation handle (unique identifier)')
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
            ->description('Preview changes without creating')
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
        $title = $arguments['title'] ?? ucfirst(str_replace(['_', '-'], ' ', $handle));
        $sites = $arguments['sites'] ?? ['default'];
        $blueprint = $arguments['blueprint'] ?? null;
        $collections = $arguments['collections'] ?? [];
        $maxDepth = $arguments['max_depth'] ?? null;
        $expectsRoot = $arguments['expects_root'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        // Validate handle
        if (Nav::find($handle)) {
            return $this->createErrorResponse("Navigation '{$handle}' already exists")->toArray();
        }

        $config = [
            'title' => $title,
            'sites' => $sites,
        ];

        if ($blueprint) {
            $config['blueprint'] = $blueprint;
        }

        if (! empty($collections)) {
            $config['collections'] = $collections;
        }

        if ($maxDepth) {
            $config['max_depth'] = $maxDepth;
        }

        if ($expectsRoot) {
            $config['expects_root'] = $expectsRoot;
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_create' => [
                    'handle' => $handle,
                    'title' => $title,
                    'config' => $config,
                ],
            ];
        }

        try {
            // Create the navigation
            $nav = Nav::make($handle);
            $nav->title($title);
            $nav->sites($sites);

            if ($blueprint) {
                $nav->blueprint($blueprint);
            }

            if (! empty($collections)) {
                $nav->collections($collections);
            }

            if ($maxDepth) {
                $nav->maxDepth($maxDepth);
            }

            if ($expectsRoot) {
                $nav->expectsRoot($expectsRoot);
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
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not create navigation: ' . $e->getMessage())->toArray();
        }
    }
}
