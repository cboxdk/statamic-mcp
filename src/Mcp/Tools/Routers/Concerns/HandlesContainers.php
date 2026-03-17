<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Stache;

/**
 * Asset container operations for the AssetsRouter.
 */
trait HandlesContainers
{
    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function listContainers(array $arguments): array
    {
        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $includeCounts = $this->getBooleanArgument($arguments, 'include_counts', false);
            $containers = AssetContainer::all()->map(function ($container) use ($includeDetails, $includeCounts) {
                if (! $container instanceof AssetContainerContract) {
                    return null;
                }

                $data = [
                    'handle' => $container->handle(),
                    'title' => $container->title(),
                    'disk' => $container->diskHandle(),
                ];

                if ($includeDetails) {
                    $permissions = $this->getContainerPermissions($container);
                    $data = array_merge($data, [
                        'blueprint' => $container->blueprint()?->handle(),
                        'url' => $container->url(),
                        'path' => $container->path(),
                        'allow_uploads' => $permissions['allow_uploads'],
                        'allow_downloading' => $permissions['allow_downloading'],
                        'allow_renaming' => $permissions['allow_renaming'],
                        'allow_moving' => $permissions['allow_moving'],
                        'create_folders' => $permissions['create_folders'],
                        'search_index' => $this->getContainerSearchIndex($container),
                    ]);

                    if ($includeCounts) {
                        $data['asset_count'] = $this->getContainerAssetCount($container);
                    }
                }

                return $data;
            })->all();

            return [
                'containers' => $containers,
                'total' => count($containers),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list containers: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function getContainer(array $arguments): array
    {
        try {
            $handle = $this->getStringArgument($arguments, 'handle');
            $container = AssetContainer::find($handle);

            if (! $container) {
                return $this->createErrorResponse("Asset container not found: {$handle}")->toArray();
            }

            $permissions = $this->getContainerPermissions($container);
            $includeCounts = $this->getBooleanArgument($arguments, 'include_counts', true);
            $data = [
                'handle' => $container->handle(),
                'title' => $container->title(),
                'disk' => $container->diskHandle(),
                'blueprint' => $container->blueprint()?->handle(),
                'url' => $container->url(),
                'path' => $container->path(),
                'allow_uploads' => $permissions['allow_uploads'],
                'allow_downloading' => $permissions['allow_downloading'],
                'allow_renaming' => $permissions['allow_renaming'],
                'allow_moving' => $permissions['allow_moving'],
                'create_folders' => $permissions['create_folders'],
                'search_index' => $this->getContainerSearchIndex($container),
            ];

            if ($includeCounts) {
                $data['asset_count'] = $this->getContainerAssetCount($container);
                $data['folder_count'] = $this->getContainerFolderCount($container);
            }

            return ['container' => $data];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get container: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function createContainer(array $arguments): array
    {
        if (! $this->hasPermission('create', 'asset_containers')) {
            return $this->createErrorResponse('Permission denied: Cannot create asset containers')->toArray();
        }

        try {
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $handle = $this->getStringArgument($data, 'handle') ?: $this->getStringArgument($arguments, 'handle');

            if (! $handle) {
                return $this->createErrorResponse('Container handle is required')->toArray();
            }

            $existsError = $this->checkHandleNotExists(AssetContainer::find($handle), 'Container', $handle);
            if ($existsError !== null) {
                return $existsError;
            }

            $container = AssetContainer::make($handle);

            // Set configuration
            if (isset($data['title'])) {
                $container->title(is_string($data['title']) ? $data['title'] : '');
            }
            if (isset($data['disk'])) {
                $container->disk(is_string($data['disk']) ? $data['disk'] : '');
            }

            // Set permissions
            $this->setContainerPermissions($container, $data);

            $container->save();

            // Clear caches
            Stache::clear();

            return [
                'container' => [
                    'handle' => $container->handle(),
                    'title' => $container->title(),
                    'created' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create container: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function updateContainer(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'asset_containers')) {
            return $this->createErrorResponse('Permission denied: Cannot update asset containers')->toArray();
        }

        try {
            $handle = $this->getStringArgument($arguments, 'handle');
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            $container = AssetContainer::find($handle);
            if (! $container) {
                return $this->createErrorResponse("Asset container not found: {$handle}")->toArray();
            }

            // Update basic configuration
            if (isset($data['title'])) {
                $container->title(is_string($data['title']) ? $data['title'] : '');
            }
            if (isset($data['disk'])) {
                $container->disk(is_string($data['disk']) ? $data['disk'] : '');
            }

            // Update permissions
            $this->setContainerPermissions($container, $data);

            $container->save();

            // Clear caches
            Stache::clear();

            return [
                'container' => [
                    'handle' => $container->handle(),
                    'title' => $container->title(),
                    'updated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update container: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function deleteContainer(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'asset_containers')) {
            return $this->createErrorResponse('Permission denied: Cannot delete asset containers')->toArray();
        }

        try {
            $handle = $this->getStringArgument($arguments, 'handle');
            $container = AssetContainer::find($handle);

            if (! $container) {
                return $this->createErrorResponse("Asset container not found: {$handle}")->toArray();
            }

            // Check for existing assets
            $assetCount = $container->assets()->count();
            if ($assetCount > 0) {
                return $this->createErrorResponse("Cannot delete container '{$handle}' - it contains {$assetCount} assets")->toArray();
            }

            $container->delete();

            // Clear caches
            Stache::clear();

            return [
                'container' => [
                    'handle' => $handle,
                    'deleted' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete container: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Get container permission settings.
     * In Statamic 6, permissions are user-based, not container-based.
     *
     * @return array<string, bool|null>
     */
    protected function getContainerPermissions(AssetContainerContract $container): array
    {
        return [
            'allow_uploads' => true,
            'allow_downloading' => true,
            'allow_renaming' => true,
            'allow_moving' => true,
            'create_folders' => true,
        ];
    }

    /**
     * Set container permissions (no-op in Statamic 6).
     *
     * @param  array<string, mixed>  $data
     */
    protected function setContainerPermissions(AssetContainerContract $container, array $data): void
    {
        // Permissions are user-based in Statamic 6, not container-based
    }

    /**
     * Get container search index.
     */
    protected function getContainerSearchIndex(AssetContainerContract $container): ?string
    {
        try {
            return $container->searchIndex();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get container asset count.
     */
    protected function getContainerAssetCount(AssetContainerContract $container): int
    {
        try {
            return $container->assets()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Get container folder count.
     */
    protected function getContainerFolderCount(AssetContainerContract $container): int
    {
        try {
            return $container->folders()->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
