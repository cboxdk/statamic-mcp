<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Statamic\Facades\Nav;
use Statamic\Facades\Site;

/**
 * Navigation operations for the StructuresRouter.
 */
trait HandlesNavigations
{
    /**
     * Handle navigation operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleNavigationAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listNavigations($arguments),
            'get' => $this->getNavigation($arguments),
            'create' => $this->createNavigation($arguments),
            'update' => $this->updateNavigation($arguments),
            'delete' => $this->deleteNavigation($arguments),
            'configure' => $this->configureNavigation($arguments),
            default => $this->createErrorResponse("Unknown navigation action: {$action}")->toArray(),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listNavigations(array $arguments): array
    {
        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $navigations = Nav::all()->map(function ($navigation) use ($includeDetails) {
                /** @var \Statamic\Contracts\Structures\Nav $navigation */
                $data = [
                    'handle' => $navigation->handle(),
                    'title' => $navigation->title(),
                ];

                if ($includeDetails) {
                    $data = array_merge($data, [
                        'collections' => $navigation->collections()->map->handle()->all(),
                        'max_depth' => $navigation->maxDepth(),
                        'sites' => $navigation->sites()->all(),
                    ]);
                }

                return $data;
            })->all();

            return [
                'navigations' => $navigations,
                'total' => count($navigations),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list navigations: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getNavigation(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $navigation = Nav::find($handle);

            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $navigation->handle(),
                'title' => $navigation->title(),
                'collections' => $navigation->collections()->map->handle()->all(),
                'max_depth' => $navigation->maxDepth(),
                'sites' => $navigation->sites()->all(),
                'trees' => $navigation->trees()->map(function ($tree) {
                    return [
                        'site' => $tree->locale(),
                        'items' => $tree->tree(),
                    ];
                })->all(),
            ];

            return ['navigation' => $data];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get navigation: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createNavigation(array $arguments): array
    {
        if (! $this->hasPermission('create', 'navigation')) {
            return $this->createErrorResponse('Permission denied: Cannot create navigation')->toArray();
        }

        try {
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $handle = is_string($data['handle'] ?? null) ? $data['handle'] : (is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null);

            if (! $handle) {
                return $this->createErrorResponse('Navigation handle is required')->toArray();
            }

            $existsError = $this->checkHandleNotExists(Nav::find($handle), 'Navigation', $handle);
            if ($existsError !== null) {
                return $existsError;
            }

            $navigation = Nav::make($handle);

            if (isset($data['title'])) {
                $navigation->title($data['title']);
            }

            if (isset($data['max_depth'])) {
                $navigation->maxDepth($data['max_depth']);
            }

            $navigation->save();

            // Clear caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'navigation' => [
                    'handle' => $navigation->handle(),
                    'title' => $navigation->title(),
                    'created' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create navigation: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateNavigation(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'navigation')) {
            return $this->createErrorResponse('Permission denied: Cannot update navigation')->toArray();
        }

        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            $navigation = Nav::find($handle);
            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            if (isset($data['title'])) {
                $navigation->title($data['title']);
            }

            if (isset($data['max_depth'])) {
                $navigation->maxDepth($data['max_depth']);
            }

            $navigation->save();

            // Clear caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'navigation' => [
                    'handle' => $navigation->handle(),
                    'title' => $navigation->title(),
                    'updated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update navigation: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteNavigation(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'navigation')) {
            return $this->createErrorResponse('Permission denied: Cannot delete navigation')->toArray();
        }

        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $navigation = Nav::find($handle);

            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            $navigation->delete();

            // Clear caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'navigation' => [
                    'handle' => $handle,
                    'deleted' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete navigation: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function configureNavigation(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $config = is_array($arguments['config'] ?? null) ? $arguments['config'] : [];

            $navigation = Nav::find($handle);
            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            // Determine site to use (with fallback to default)
            $defaultSite = Site::default();
            $defaultSiteHandle = is_object($defaultSite) && method_exists($defaultSite, 'handle') ? (string) $defaultSite->handle() : 'default';
            $site = is_string($config['site'] ?? null) ? $config['site'] : $defaultSiteHandle;

            // Handle specific configuration options
            if (isset($config['title'])) {
                $navigation->title($config['title']);
            }

            if (isset($config['max_depth'])) {
                $navigation->maxDepth($config['max_depth']);
            }

            if (isset($config['collections'])) {
                $navigation->collections($config['collections']);
            }

            if (isset($config['tree'])) {
                if (! is_array($config['tree'])) {
                    return $this->createErrorResponse('Navigation tree must be an array')->toArray();
                }
                $encoded = json_encode($config['tree']);
                if ($encoded === false || strlen($encoded) > 1048576) { // 1MB limit
                    return $this->createErrorResponse('Navigation tree data exceeds maximum size (1MB)')->toArray();
                }

                // Set the navigation tree structure
                $tree = $navigation->in($site);
                if (! $tree) {
                    $tree = $navigation->makeTree($site);
                }
                $tree->tree($config['tree']);
                $tree->save();
            }

            // Save the navigation
            $navigation->save();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'navigation' => [
                    'handle' => $navigation->handle(),
                    'title' => $navigation->title(),
                    'config' => $navigation->toArray(),
                    'tree' => $navigation->in($site)?->tree() ?? [],
                ],
                'configured' => true,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to configure navigation: {$e->getMessage()}")->toArray();
        }
    }
}
